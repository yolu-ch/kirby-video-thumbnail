<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\LogicException;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\Dir;
use function Yolu\VideoThumbnail\saveFrame;

require getenv('KIRBY_TEST_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
$root = sys_get_temp_dir() . '/video-thumbnail-' . bin2hex(random_bytes(8));
Dir::make($root . '/site/plugins');
symlink(dirname(__DIR__), $root . '/site/plugins/video-thumbnail');
Dir::make($root . '/content/home');
file_put_contents($root . '/content/home/default.txt', "Title: Home\n");
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$conflict = static function (Closure $action): void {
    try {
        $action();
    } catch (LogicException) {
        return;
    }
    throw new RuntimeException('Expected a filename conflict.');
};
try {
    $app = new App([
        'roots' => ['index' => $root],
        'users' => [['id' => 'editor', 'email' => 'editor@example.test', 'role' => 'editor']],
        'roles' => [['name' => 'editor', 'permissions' => ['files' => ['create' => true, 'read' => true, 'replace' => false, 'update' => false]]]],
        'options' => ['debug' => true, 'yolu.video-thumbnail.prefix' => 'p-', 'yolu.video-thumbnail.suffix' => '', 'yolu.video-thumbnail.extension' => 'png'],
    ]);
    $app->impersonate('kirby');
    $page = $app->page('home');
    $source = $root . '/clip.mp4';
    file_put_contents($source, "\0\0\0\x18ftypmp42\0\0\0\0mp42isom");
    $image = $root . '/frame.png';
    imagepng(imagecreatetruecolor(24, 16), $image);
    $video = fn (string $name): File => $page->createFile(['source' => $source, 'filename' => $name . '.mp4', 'template' => 'video']);
    $photo = fn (string $name, string $template): File => $page->createFile(['source' => $image, 'filename' => $name, 'template' => $template]);
    $clip = $video('clip');
    $poster = saveFrame($clip, $image, 2);
    $check($poster->filename() === 'p-clip.png' && $poster->isManagedVideoPoster(), 'Configured filename and ownership');
    $uuid = $poster->uuid()->toString();
    $poster->update(['alt' => 'Existing caption']);
    $poster = saveFrame($clip, $image);
    $check($poster->uuid()->toString() === $uuid && $poster->alt()->value() === 'Existing caption', 'Replacement preserves identity and metadata');
    $conflict(fn () => $poster->changeName('independent'));
    $unrelated = $photo('p-occupied.png', 'image');
    $conflict(fn () => $clip->changeName('occupied'));
    $check($clip->exists() && $poster->exists() && $unrelated->exists(), 'Collision preserves both files');
    $clip = $clip->changeName('renamed');
    $check($clip->videoThumb()?->uuid()?->toString() === $uuid && !is_file($poster->root()), 'Paired rename');
    $clip->delete();
    $check(!is_file($page->root() . '/p-renamed.png'), 'Deletion removes owned poster');
    $clip = $video('source');
    $poster = saveFrame($clip, $image, 2);
    $posterUuid = $poster->uuid()->toString();
    $clip = $page->file('source.mp4')->replace($source);
    $check($clip->videoThumb()?->uuid()?->toString() === $posterUuid, 'Replacing the video keeps its selected poster');
    $clip = $page->file('source.mp4')->changeName('replacement');
    $check($clip->videoThumb()?->uuid()?->toString() === $posterUuid && !is_file($poster->root()), 'Renaming a replaced video resolves ownership through its current UUID');
    $clip->delete();
    $check(!is_file($page->root() . '/p-replacement.png'), 'Deletion after source replacement and rename');
    $clip = $video('occupied');
    $conflict(fn () => saveFrame($clip, $image, adopt: true));
    $clip->delete();
    $check($unrelated->exists(), 'Unrelated image survives');
    $clip = $video('twins');
    $page->createFile(['source' => $source, 'filename' => 'twins.mov', 'template' => 'video']);
    $conflict(fn () => saveFrame($clip, $image));
    $check(!$page->file('p-twins.png'), 'Videos sharing a basename cannot claim the same poster');
    $legacy = $photo('p-legacy.png', 'thumb');
    $clip = $video('legacy');
    $check($clip->videoThumb()?->id() === $legacy->id() && !$legacy->isManagedVideoPoster(), 'Legacy display without mutation');
    $conflict(fn () => saveFrame($clip, $image));
    $clip->delete();
    $check($legacy->exists(), 'Unclaimed legacy poster survives');
    $clip = $video('legacy');
    $claimed = saveFrame($clip, $image, adopt: true);
    $check($claimed->uuid()->id() === $legacy->uuid()->id() && $claimed->isManagedVideoPoster(), 'Explicit legacy adoption');
    $lost = $photo('p-orphan.png', 'thumb')->update(['videoThumbnailOwner' => 'file://missing-owner']);
    $check(!$lost->isManagedVideoPoster(), 'A missing owner leaves the image accessible');
    $app->impersonate('editor');
    $new = $video('editor');
    $poster = saveFrame($new, $image, 3);
    $check($poster->isManagedVideoPoster() && $app->user()?->id() === 'editor', 'Create-only uploader owns first capture');
    try {
        saveFrame($new, $image);
        throw new RuntimeException('Create-only user replaced a poster');
    } catch (PermissionException) {
        $check($app->user()?->id() === 'editor', 'A failed replacement restores the uploader');
    }
    echo "Video poster creation, replacement, rename, deletion, collisions, adoption and editor permissions passed.\n";
} finally {
    $app->session()->commit();
    App::destroy();
    Dir::remove($root);
}
