<?php

namespace Yolu\VideoThumbnail;

use Closure;
use Kirby\Cms\File;
use Kirby\Exception\LogicException;
use Kirby\Exception\PermissionException;

function ownerId(File $poster): string
{
    return (string)$poster->content($poster->kirby()->defaultLanguage()?->code())->get('videoThumbnailOwner')->value();
}

function isManagedPoster(File $poster): bool
{
    if ($poster->type() !== 'image' || ($owner = ownerId($poster)) === '') {
        return false;
    }
    $video = $poster->kirby()->file($owner);
    return $video?->type() === 'video' && $video->uuid()?->toString() === $owner && $video->parent()->root() === $poster->parent()->root();
}

function ownedPoster(File $video, bool $recover = false, ?string $videoName = null): ?File
{
    $id = $video->uuid()?->toString();
    if (!$id) {
        return null;
    }
    $poster = $video->parent()->file(thumbnailFilenameFor($videoName ?? $video->name()));
    if ($poster && $poster->type() === 'image' && ownerId($poster) === $id) {
        return $poster;
    }
    // A changed naming option or an interrupted rename may leave the old filename.
    return $recover ? $video->parent()->images()->filter(fn (File $image) => ownerId($image) === $id)->first() : null;
}

function displayPoster(File $video): ?File
{
    $poster = $video->parent()->file(thumbnailFilenameFor($video->name()));
    if (!$poster || $poster->type() !== 'image') {
        return null;
    }
    if (ownerId($poster) !== '') {
        return ownedPoster($video);
    }
    return $poster->template() === thumbnailTemplate() && $video->parent()->videos()->filterBy('name', $video->name())->count() === 1 ? $poster : null;
}

function ensureDestination(File $video, string $name): void
{
    $parent = $video->parent();
    $otherVideo = $parent->videos()->not($video)->filterBy('name', $name)->first();
    $existing = $parent->file(thumbnailFilenameFor($name));
    if ($otherVideo || ($existing && $existing->id() !== ownedPoster($video, true)?->id())) {
        throw new LogicException(message: t('video-thumbnail.collision'));
    }
}

/** Only the paired rename may bypass the independent poster rename guard. */
final class PosterRename
{
    private static ?File $poster = null;

    public static function allows(File $file): bool
    {
        return self::$poster?->id() === $file->id();
    }

    public static function with(File $poster, Closure $rename): void
    {
        self::$poster = $poster;
        try {
            $rename();
        } finally {
            self::$poster = null;
        }
    }
}

function saveFrame(File $video, string $source, mixed $duration = null, bool $adopt = false): File
{
    $kirby = $video->kirby();
    $uuid = $video->uuid();
    if (!$uuid || $video->type() !== 'video' || !$video->isAccessible()) {
        throw new PermissionException();
    }
    $owned = ownedPoster($video, true);
    if ($owned && $owned->filename() !== thumbnailFilenameFor($video->name())) {
        throw new LogicException(message: t('video-thumbnail.namingChanged'));
    }
    $legacy = $adopt && !$owned ? displayPoster($video) : null;
    if (!$legacy) {
        ensureDestination($video, $video->name());
    }
    $poster = $legacy ?? $owned;
    $session = $kirby->session();
    $recent = $session->get('video-thumbnail.uploads', []);
    $initial = !$poster && ($recent[$uuid->id()] ?? 0) >= time();
    if (!$initial && !$video->guards()->isAvailable('replace')) {
        throw new PermissionException();
    }
    $expected = match (thumbnailExtension()) {
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'image/jpeg'
    };
    if ((@getimagesize($source)['mime'] ?? null) !== $expected) {
        throw new LogicException(message: 'The captured frame has an unexpected image format.');
    }
    if ($poster) {
        $poster = $poster->replace($source);
        return $legacy ? $kirby->impersonate('kirby', fn () => $poster->save(['videoThumbnailOwner' => $uuid->toString()], $kirby->defaultLanguage()?->code())) : $poster;
    }
    $poster = $video->parent()->createFile([
        'filename' => thumbnailFilenameFor($video->name()),
        'source' => $source, 'template' => thumbnailTemplate(),
        'content' => ['videoThumbnailOwner' => $uuid->toString()]
    ]);
    if ($initial && is_numeric($duration) && is_finite((float)$duration) && (float)$duration >= 0) {
        $kirby->impersonate('kirby', fn () => $video->update(['duration' => round((float)$duration, 2)]));
    }
    unset($recent[$uuid->id()]);
    $session->set('video-thumbnail.uploads', $recent);
    return $poster;
}
