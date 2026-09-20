<?php
/**
 * Profile photo upload: only real JPG / PNG / WebP images up to 2 MB.
 * The file gets a random name, so the uploaded name is never used on disk.
 * Returns [filename|null, error|''].
 */
const AVATAR_DIR = __DIR__ . '/../uploads/avatars/';
const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

function save_avatar(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'Please choose a photo.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > AVATAR_MAX_BYTES) {
        return [null, 'The photo must be 2 MB or smaller.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'The upload failed. Please try again.'];
    }
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $info  = @getimagesize($file['tmp_name']);
    $mime  = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) : ($info['mime'] ?? '');
    if (!$info || !isset($types[$info[2]]) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return [null, 'Only JPG, PNG or WebP images are allowed.'];
    }
    if ($info[0] < 64 || $info[1] < 64 || $info[0] > 6000 || $info[1] > 6000) {
        return [null, 'The image must be between 64 and 6000 pixels wide and high.'];
    }
    if (!is_dir(AVATAR_DIR) && !mkdir(AVATAR_DIR, 0755, true)) {
        return [null, 'The uploads folder is not writable.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], AVATAR_DIR . $name)) {
        return [null, 'Could not save the photo. Check that uploads/avatars is writable.'];
    }
    return [$name, ''];
}

function delete_avatar(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name) && is_file(AVATAR_DIR . $name)) {
        @unlink(AVATAR_DIR . $name);
    }
}
