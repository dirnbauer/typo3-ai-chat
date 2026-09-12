<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Throwable;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;

/**
 * Where a chat attachment lives: `<uploadFolder>/<be_user>/<conversation>/`.
 *
 * The path is per user and per conversation rather than one flat folder,
 * because both halves are needed later: retention deletes a conversation's
 * files without touching another's, and an administrator looking at an upload
 * can see whose it was from the path alone.
 */
final readonly class AttachmentStorage
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ExtensionConfiguration $config,
    ) {}

    /**
     * The target folder, created on first use.
     */
    public function folderFor(int $beUserUid, int $conversationUid): Folder
    {
        [$storage, $basePath] = $this->resolveBase();

        $path = rtrim($basePath, '/') . '/' . $beUserUid . '/' . $conversationUid;

        return $this->ensureFolder($storage, $path);
    }

    /**
     * Delete every file below a conversation's folder, then the folder itself.
     * Best effort: a storage that is gone, read-only or missing the folder is
     * not an error — retention must not stall on one unreachable file.
     */
    public function deleteConversationFiles(int $beUserUid, int $conversationUid): int
    {
        try {
            [$storage, $basePath] = $this->resolveBase();
        } catch (Throwable) {
            return 0;
        }

        $path = rtrim($basePath, '/') . '/' . $beUserUid . '/' . $conversationUid;
        if (!$storage->hasFolder($path)) {
            return 0;
        }

        try {
            $folder = $storage->getFolder($path);
            $deleted = count($folder->getFiles());
            $storage->deleteFolder($folder, true);

            return $deleted;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array{0: ResourceStorage, 1: string}
     */
    private function resolveBase(): array
    {
        $configured = $this->config->getUploadFolder();

        $storageUid = 0;
        $path = $configured;
        if (str_contains($configured, ':')) {
            [$rawUid, $path] = explode(':', $configured, 2);
            $storageUid = (int)$rawUid;
        }

        $storage = $storageUid > 0
            ? $this->storageRepository->findByUid($storageUid)
            : $this->storageRepository->getDefaultStorage();

        if (!$storage instanceof ResourceStorage) {
            throw new FolderDoesNotExistException(
                sprintf('The configured AI Chat upload storage "%s" does not exist.', $configured),
                1794000001,
            );
        }

        return [$storage, '/' . trim($path, '/')];
    }

    private function ensureFolder(ResourceStorage $storage, string $path): Folder
    {
        if ($storage->hasFolder($path)) {
            return $storage->getFolder($path);
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $s): bool => $s !== ''));
        $folder = $storage->getRootLevelFolder();
        foreach ($segments as $segment) {
            $folder = $folder->hasFolder($segment)
                ? $folder->getSubfolder($segment)
                : $storage->createFolder($segment, $folder);
        }

        return $folder;
    }

    /**
     * The JSON shape a stored attachment takes on a message row.
     *
     * @return array{fileUid: int, fileName: string, fileMimeType: string, fileSize: int}
     */
    public function describe(File $file): array
    {
        return [
            'fileUid' => $file->getUid(),
            'fileName' => $file->getName(),
            'fileMimeType' => $file->getMimeType(),
            'fileSize' => $file->getSize(),
        ];
    }
}
