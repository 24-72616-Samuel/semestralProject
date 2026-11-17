<?php
/**
 * VEHICLE PHOTOS
 * --------------
 * Customers attach 1-4 photos of their vehicle when booking. The owner / admin / staff look at them and pick the
 * vehicle size (which decides the price). Photos live in uploads/vehicles/ (direct web access is blocked by .htaccess)
 * and are only shown through photo.php, which checks who is asking.
 * Every upload is re-encoded as a JPEG (max 1600px) with GD, which also strips hidden data from the file.
 */
const PHOTO_MAX = 4;

function photo_dir(): string {
    $d = __DIR__ . '/../uploads/vehicles';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    $ht = $d . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    return $d;
}

/** Save uploaded photos from $_FILES['photos']. Returns [list of saved file names, error text or null]. */
function photos_save(array $f, bool $required = true, int $max = PHOTO_MAX): array {
    $names = [];
    $items = [];
    foreach ((array)($f['name'] ?? []) as $i => $n) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $items[] = ['tmp' => $f['tmp_name'][$i], 'err' => $f['error'][$i]];
    }
    if (!$items) return $required ? [[], 'Please add at least one photo of your vehicle.'] : [[], null];
    if (count($items) > $max) return [[], 'You can add up to ' . $max . ' photo' . ($max > 1 ? 's' : '') . '.'];

    $dir = photo_dir();
    foreach ($items as $it) {
        $err = null;
        if (in_array($it['err'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) $err = 'A photo is too large. Try a smaller one.';
        elseif ($it['err'] !== UPLOAD_ERR_OK || !is_uploaded_file($it['tmp'])) $err = 'A photo could not be uploaded. Please try again.';
        else {
            $info = @getimagesize($it['tmp']);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) $err = 'Photos must be JPG, PNG or WebP images.';
        }
        if (!$err && $info && ($info[0] * $info[1]) > 40000000) $err = 'That photo is too big (over 40 megapixels). Use a smaller picture.';
        if ($err) { photos_delete($names); return [[], $err]; }

        $name = bin2hex(random_bytes(16)) . '.jpg';
        $ok = false;
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string)file_get_contents($it['tmp']));
            if ($img) {
                // Phones store rotation in EXIF; apply it before the metadata is dropped.
                if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                    $o = @exif_read_data($it['tmp'])['Orientation'] ?? 1;
                    $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                    if ($deg && ($rot = imagerotate($img, $deg, 0))) $img = $rot;
                }
                $w = imagesx($img); $h = imagesy($img);
                $scale = min(1, 1600 / max($w, $h));
                $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
                $canvas = imagecreatetruecolor($nw, $nh);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));   // PNG transparency -> white
                imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $ok = imagejpeg($canvas, $dir . '/' . $name, 82);
            }
        } else {
            $ok = move_uploaded_file($it['tmp'], $dir . '/' . $name);   // no GD: keep the validated original
        }
        if (!$ok) { photos_delete($names); return [[], 'Could not process one of the photos. Try another picture.']; }
        $names[] = $name;
    }
    return [$names, null];
}

function photos_delete(array $names): void {
    foreach ($names as $n) @unlink(photo_dir() . '/' . basename($n));
}

/** Photos for many reservations at once: [reservation_id => [[id, file_name], ...]]. */
function photos_for(PDO $pdo, array $reservationIds): array {
    if (!$reservationIds) return [];
    $in = implode(',', array_map('intval', $reservationIds));
    $out = [];
    foreach ($pdo->query("SELECT id, reservation_id FROM reservation_photos WHERE reservation_id IN ($in) ORDER BY id")->fetchAll() as $p) {
        $out[(int)$p['reservation_id']][] = (int)$p['id'];
    }
    return $out;
}
