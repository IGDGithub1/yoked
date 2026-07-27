<?php
declare(strict_types=1);

/**
 * Image ingest for progress photos (SPEC-coaching §7.2).
 *
 * ADAPTED FROM FRIENDSPACE'S Media::ingestImage, which is a working implementation of the same
 * problem, minus the parts Yoked does not have: no animated GIFs, no audio, no video, no
 * rotation endpoint. A progress photo is a still JPEG or PNG from a phone.
 *
 * THREE THINGS MAKE AN UPLOAD SAFE, and all three are load-bearing:
 *
 *   THE MIME IS SNIFFED FROM THE BYTES, never taken from the browser. A client can claim any
 *   content-type it likes, and "image/jpeg" on a PHP file is the oldest upload attack there is.
 *
 *   EVERY IMAGE IS RE-ENCODED through imagick. That is what neutralises a file which is a valid
 *   image AND a valid script — the polyglot case — because the bytes that come out are the ones
 *   imagick wrote, not the ones that were uploaded. It also strips EXIF, which on a progress
 *   photo means GPS coordinates of the user's home.
 *
 *   THEY ARE STORED OUTSIDE THE WEB ROOT. storage/uploads is not reachable by URL, so even a
 *   file that somehow survived the above cannot be executed by requesting it. Serving goes
 *   through a route that checks who is asking (§10.4: a buddy sees sessions, not photos).
 *
 * EXIF ORIENTATION IS APPLIED BEFORE STRIPPING. A phone photo is often "sideways pixels plus a
 * rotate flag", and stripping the flag first leaves the photo on its side forever.
 */
final class Media
{
    /** 8 MB. A phone photo is 2-5 MB; beyond this is a screenshot of something else. */
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    /**
     * Sniffed MIME to canonical extension. Only these are accepted.
     *
     * No GIF and no WebP, unlike friendspace. A progress photo comes from a camera, and
     * narrowing the accepted set narrows the attack surface for free.
     */
    public const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/heic' => 'jpg',   // iOS default; re-encoded to JPEG on the way in
    ];

    /** Longest edge, in pixels. Originals are re-encoded but never upsized. */
    public const IMG_FULL  = 1600;
    public const IMG_THUMB = 320;

    private static function uploadsDir(): string
    {
        $dir = yk_config('uploads_dir');
        if (!$dir) {
            throw new RuntimeException('uploads_dir is not configured');
        }
        return rtrim((string) $dir, '/');
    }

    /** Ensure the subdirectory exists and is writable. */
    private static function ensureDir(string $sub): string
    {
        $path = self::uploadsDir() . '/' . $sub;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Cannot create storage dir: {$path}");
        }
        return $path;
    }

    /** The true MIME, from the bytes on disk rather than from the client. */
    private static function sniff(string $tmpPath): string
    {
        $f = new finfo(FILEINFO_MIME_TYPE);
        $mime = $f->file($tmpPath);
        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /**
     * Ingest an uploaded progress photo. $file is one entry from $_FILES.
     *
     * Returns the media row id. Errors respond directly and exit, matching how the rest of the
     * routes behave — a half-ingested upload has nothing worth reporting to the caller.
     */
    public static function ingestPhoto(array $file, int $ownerId): int
    {
        self::validateUpload($file, self::MAX_IMAGE_BYTES);

        $mime = self::sniff($file['tmp_name']);
        if (!isset(self::IMAGE_TYPES[$mime])) {
            Response::error('Use a JPEG or PNG photo.', 422);
        }

        /*
         * No imagick means no safe upload, so this refuses rather than degrading.
         *
         * Storing an un-re-encoded file would keep the EXIF (home GPS) and leave a polyglot
         * executable on disk. Better to fail loudly: the extension is present under the web SAPI
         * on this host, verified 2026-07-25, and its absence would mean something changed.
         */
        if (!extension_loaded('imagick')) {
            error_log('[yoked] photo upload attempted without imagick');
            Response::error('Photo uploads are unavailable right now.', 503);
        }

        $dir  = self::ensureDir('photos');
        $base = bin2hex(random_bytes(16));
        $ext  = self::IMAGE_TYPES[$mime];

        try {
            $img = new Imagick($file['tmp_name']);
        } catch (Throwable $e) {
            Response::error('That file could not be read as a photo.', 422);
        }

        // Before stripImage, or a sideways phone photo stays sideways.
        try {
            $img->autoOrientImage();
        } catch (Throwable $e) {
            // No orientation data. Nothing to correct.
        }

        $origW = $img->getImageWidth();
        $origH = $img->getImageHeight();

        // HEIC in, JPEG out. Browsers cannot display HEIC.
        $img->setImageFormat($ext === 'png' ? 'png' : 'jpeg');
        $img->stripImage();

        $paths = [];

        /*
         * The "full" copy is capped at 1600px.
         *
         * A progress photo is looked at on a phone and compared with one from a month ago;
         * nobody needs 4032px of it, and the cap keeps the storage bill of a weekly upload
         * per user in the tens of megabytes a year rather than hundreds.
         */
        $full = clone $img;
        if (max($origW, $origH) > self::IMG_FULL) {
            $full->thumbnailImage(self::IMG_FULL, self::IMG_FULL, true);
        }
        $full->writeImage($dir . "/{$base}.{$ext}");
        $paths['full'] = "photos/{$base}.{$ext}";

        $thumb = clone $img;
        $thumb->thumbnailImage(self::IMG_THUMB, self::IMG_THUMB, true);
        $thumb->writeImage($dir . "/{$base}_thumb.{$ext}");
        $paths['thumb'] = "photos/{$base}_thumb.{$ext}";

        $stored = (int) filesize($dir . '/' . basename($paths['full']));
        $img->clear();
        $full->clear();
        $thumb->clear();

        return (int) DB::insert(
            'INSERT INTO media (owner_id, kind, path, mime, size_bytes, width, height, variants)
             VALUES (?, "progress_photo", ?, ?, ?, ?, ?, ?)',
            [
                $ownerId,
                $paths['full'],
                $ext === 'png' ? 'image/png' : 'image/jpeg',
                $stored,
                $origW,
                $origH,
                json_encode($paths),
            ]
        );
    }

    private static function validateUpload(array $file, int $maxBytes): void
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            Response::error('That photo is too large.', 413);
        }
        if ($err !== UPLOAD_ERR_OK) {
            Response::error('The upload did not finish. Try again.', 400);
        }
        if (($file['size'] ?? 0) <= 0) {
            Response::error('That file was empty.', 422);
        }
        if ($file['size'] > $maxBytes) {
            Response::error(
                'That photo is too large (max ' . round($maxBytes / 1048576) . ' MB).',
                413
            );
        }
        /*
         * is_uploaded_file, not is_readable.
         *
         * It verifies the path came from THIS request's upload rather than being any readable
         * path on the server. Without it, a crafted request naming /etc/passwd as its tmp_name
         * would have that file ingested and served back.
         */
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            Response::error('That upload could not be read.', 400);
        }
    }

    /** A media row, or null. */
    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /** Absolute path for a stored relative path, or null if it escapes the store. */
    public static function absPath(string $relPath): ?string
    {
        $base = self::uploadsDir();
        $abs  = realpath($base . '/' . $relPath);
        /*
         * realpath then prefix-check, which is what stops "../../../etc/passwd".
         *
         * The stored paths are ours and contain no traversal, but this function takes whatever
         * is in the database and a check here costs nothing.
         */
        if ($abs === false || !str_starts_with($abs, realpath($base) ?: $base)) {
            return null;
        }
        return $abs;
    }

    /** Delete a photo and its variants, then the row. */
    public static function delete(int $id): void
    {
        $row = self::find($id);
        if ($row === null) {
            return;
        }
        $variants = json_decode((string) ($row['variants'] ?? '[]'), true);
        foreach (is_array($variants) ? $variants : [] as $rel) {
            $abs = self::absPath((string) $rel);
            if ($abs !== null && is_file($abs)) {
                @unlink($abs);
            }
        }
        DB::run('DELETE FROM media WHERE id = ?', [$id]);
    }
}
