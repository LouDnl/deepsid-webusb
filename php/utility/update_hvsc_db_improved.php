<?php
/**
 * DeepSID
 *
 * Update the database according to the newest HVSC Update##.hvs script.
 * The HVSC files themselves are assumed to have already been replaced.
 *
 * @used-by N/A
 */

require_once dirname(__DIR__).'/class.account.php'; // php/class.account.php

const TEST_MODE = true; // Remember to set this to FALSE for real HVSC updates

const HVSC_FULL_PATH = __DIR__.'/../../music/_High Voltage SID Collection';
const HVSC_PATH = '_High Voltage SID Collection/';
const HVSC_MAX_UPDATE_AGE_DAYS = 183;
const DB_TABLE_FILES = 'files_84';
const DB_TABLE_FOLDERS = 'folders_84';

const IGNORED_COMMANDS = [
    'REPLACE', 'CREDITS', 'TITLE', 'AUTHOR', 'RELEASED', 'SONGS', 'SPEED',
    'INITPLAY', 'FLAGS', 'CLOCK', 'SIDMODEL', 'FREEPAGE', 'FIXLOAD'
];

function output(string $text = '', string $color = ''): void
{
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($color !== '') {
        $text = '<span style="color:'.$color.';">'.$text.'</span>';
    }
    echo $text.'<br />'.PHP_EOL;
}

function normalizeHvscPath(string $line): string
{
    $path = trim(str_replace('\\', '/', $line));

    // A few update scripts have reportedly omitted the initial slash
    if (!str_starts_with($path, '/') && preg_match('~^(?:update/|MUSICIANS/|DEMOS/|GAMES/|DOCUMENTS/)~i', $path)) {
        $path = '/'.$path;
    }

    $path = ltrim($path, '/');

    // A path without a SID extension is a directory, even when its final slash
    // was accidentally omitted in the update script
    if ($path !== '' && !str_ends_with(strtolower($path), '.sid')) {
        $path = rtrim($path, '/').'/';
    }

    return $path;
}

function isHvscPathLine(string $line): bool
{
    $line = ltrim($line);
    return str_starts_with($line, '/') ||
        (bool) preg_match('~^(?:update/|MUSICIANS/|DEMOS/|GAMES/|DOCUMENTS/)~i', $line);
}

function extractSidNames(string $comment): array
{
    preg_match_all('~(?:^|\s)([^\s()]+\.sid)(?=\s|$)~i', $comment, $matches);

    $names = [];
    foreach ($matches[1] ?? [] as $name) {
        $name = basename(trim($name, " \t\n\r\0\x0B,;"));
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    return array_keys($names);
}

function ensureFolder(PDO $db, string $folder, int $hvscVersion): void
{
    $folder = rtrim($folder, '/');
    $collectionPath = HVSC_PATH.$folder;

    $select = $db->prepare(
        'SELECT id
         FROM '.DB_TABLE_FOLDERS.'
         WHERE collection_path = ?
         LIMIT 1'
    );
    $select->execute([$collectionPath]);

    $id = $select->fetchColumn();
    if ($id === false) {
        $insert = $db->prepare(
            'INSERT INTO '.DB_TABLE_FOLDERS.' (collection_path, new, type)
             VALUES (?, ?, ?)'
        );
        $insert->execute([
            $collectionPath,
            $hvscVersion,
            "SINGLE"
        ]);

        output('    Created folder row: '.$collectionPath);
    }
}

function findLatestUpdateFile(string $documentsPath): array
{
    $candidates = glob($documentsPath.DIRECTORY_SEPARATOR.'Update*.hvs') ?: [];
    $updates = [];

    foreach ($candidates as $file) {
        if (preg_match('~Update(\d+)\.hvs$~i', basename($file), $match)) {
            $updates[(int) $match[1]] = $file;
        }
    }

    if (!$updates) {
        throw new RuntimeException('No Update##.hvs file was found in '.$documentsPath);
    }

    ksort($updates, SORT_NUMERIC);
    $version = (int) array_key_last($updates);
    return [$version, $updates[$version]];
}

function removeDuplicateFolders(PDO $db): void
{
    output();
    output('Checking for duplicate folder rows...');

    $sql = 'SELECT id, collection_path FROM '.DB_TABLE_FOLDERS.' WHERE collection_path IN (
                SELECT collection_path FROM '.DB_TABLE_FOLDERS.' GROUP BY collection_path HAVING COUNT(*) > 1
            ) ORDER BY collection_path, id';

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        output('No duplicate folders found.');
        return;
    }

    $groups = [];
    foreach ($rows as $row) {
        $groups[$row['collection_path']][] = (int) $row['id'];
        output('Duplicate result: ID '.$row['id'].' — '.$row['collection_path']);
    }

    $delete = $db->prepare('DELETE FROM '.DB_TABLE_FOLDERS.' WHERE id = ?');
    foreach ($groups as $collectionPath => $ids) {
        sort($ids, SORT_NUMERIC);
        $keptId = array_shift($ids);
        output('Keeping ID '.$keptId.': '.$collectionPath);

        foreach ($ids as $id) {
            $delete->execute([$id]);
            output('    Deleted duplicate ID '.$id);
        }
    }
}

function normalizeSidFilename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
}

function findPhysicalSidPath(string $destination, string $sid): ?string
{
    $destination = trim(str_replace('\\', '/', $destination), '/').'/';

    $expectedDirectory =
        rtrim(HVSC_FULL_PATH, '\\/').
        DIRECTORY_SEPARATOR.
        str_replace('/', DIRECTORY_SEPARATOR, rtrim($destination, '/')).
        DIRECTORY_SEPARATOR;

    $expectedRelativePath = $destination.$sid;
    $expectedFullPath = $expectedDirectory.$sid;

    // ------------------------------------------------------------------
    // 1. Exact filename in expected folder
    // ------------------------------------------------------------------

    if (is_file($expectedFullPath))
        return $expectedRelativePath;

    // ------------------------------------------------------------------
    // 2. Similar filename in expected folder
    //    (Loveletter.sid -> Love_Letter.sid)
    // ------------------------------------------------------------------

    $normalizedSid = normalizeSidFilename($sid);

    if (is_dir($expectedDirectory)) {

        $matches = [];

        foreach (new DirectoryIterator($expectedDirectory) as $file) {

            if (!$file->isFile())
                continue;

            if (strtolower($file->getExtension()) !== 'sid')
                continue;

            if (normalizeSidFilename($file->getFilename()) === $normalizedSid)
                $matches[] = $destination.$file->getFilename();
        }

        if (count($matches) === 1)
            return $matches[0];

        if (count($matches) > 1) {
            output(
                '    WARNING: Multiple similar filenames in destination folder for '.$expectedRelativePath,
                '#c60'
            );
            return null;
        }
    }

    // ------------------------------------------------------------------
    // 3. Search entire HVSC
    // ------------------------------------------------------------------

    $exactMatches = [];
    $normalizedMatches = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            HVSC_FULL_PATH,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {

        if (!$file->isFile())
            continue;

        if (strtolower($file->getExtension()) !== 'sid')
            continue;

        $relativePath = substr(
            $file->getPathname(),
            strlen(rtrim(HVSC_FULL_PATH, '\\/')) + 1
        );

        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        if (strcasecmp($file->getFilename(), $sid) === 0)
            $exactMatches[] = $relativePath;

        if (normalizeSidFilename($file->getFilename()) === $normalizedSid)
            $normalizedMatches[] = $relativePath;
    }

    if (count($exactMatches) === 1)
        return $exactMatches[0];

    if (count($normalizedMatches) === 1)
        return $normalizedMatches[0];

    if (count($exactMatches) > 1)
        output(
            '    WARNING: Multiple exact filename matches for '.$expectedRelativePath,
            '#c60'
        );
    else if (count($normalizedMatches) > 1)
        output(
            '    WARNING: Multiple similar filename matches for '.$expectedRelativePath,
            '#c60'
        );

    return null;
}

try {
    $documentsPath = HVSC_FULL_PATH.DIRECTORY_SEPARATOR.'DOCUMENTS';
    [$hvscVersion, $sourceUpdateFile] = findLatestUpdateFile($documentsPath);

    output('Latest HVSC update found: Update'.$hvscVersion.'.hvs');
    output('Source: '.$sourceUpdateFile);

    $fileTime = filemtime($sourceUpdateFile);
    if ($fileTime === false) {
        throw new RuntimeException('Could not read the timestamp of '.$sourceUpdateFile);
    }

    $ageDays = (int) floor((strtotime('today') - strtotime(date('Y-m-d', $fileTime))) / 86400);
    output('Update file date: '.date('Y-m-d', $fileTime).' ('.$ageDays.' days old)');

    if ($ageDays > HVSC_MAX_UPDATE_AGE_DAYS) {
        throw new RuntimeException(
            'The newest Update##.hvs file is older than '.HVSC_MAX_UPDATE_AGE_DAYS.
            ' days. The installed HVSC tree may not be current.'
        );
    }

    $updateDirectory = dirname(__DIR__).DIRECTORY_SEPARATOR.'_update';
    if (!is_dir($updateDirectory) && !mkdir($updateDirectory, 0755, true) && !is_dir($updateDirectory)) {
        throw new RuntimeException('Could not create '.$updateDirectory);
    }

    $localUpdateFile = $updateDirectory.DIRECTORY_SEPARATOR.basename($sourceUpdateFile);
    if (is_file($localUpdateFile)) {
        output('Update'.$hvscVersion.'.hvs is already present in php/_update.');
    } else {
        if (!copy($sourceUpdateFile, $localUpdateFile)) {
            throw new RuntimeException('Could not copy the update file to '.$localUpdateFile);
        }
        output('Copied Update'.$hvscVersion.'.hvs to php/_update.');
    }

    $lines = file($localUpdateFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Could not read '.$localUpdateFile);
    }

    $db = $account->getDB();
    $db->beginTransaction();

    $insertFile = $db->prepare(
        'INSERT INTO '.DB_TABLE_FILES.' (collection_path, new)
        SELECT ?, ?
        WHERE NOT EXISTS (
            SELECT 1
            FROM '.DB_TABLE_FILES.'
            WHERE collection_path = ?
        )'
    );
    $renameFile = $db->prepare(
        'UPDATE '.DB_TABLE_FILES.' SET collection_path = ?, updated = ? WHERE collection_path = ? LIMIT 1'
    );
    $findFile = $db->prepare('SELECT id FROM '.DB_TABLE_FILES.' WHERE collection_path = ? LIMIT 1');
    $deleteFile = $db->prepare('DELETE FROM '.DB_TABLE_FILES.' WHERE collection_path = ? LIMIT 1');
    $selectFolderFiles = $db->prepare('SELECT id, collection_path FROM '.DB_TABLE_FILES.' WHERE collection_path LIKE ?');
    $updateFilePath = $db->prepare('UPDATE '.DB_TABLE_FILES.' SET collection_path = ?, updated = ? WHERE id = ?');
    $renameFolder = $db->prepare('UPDATE '.DB_TABLE_FOLDERS.' SET collection_path = ? WHERE collection_path = ?');
    $deleteEmptyFolder = $db->prepare(
        'DELETE FROM '.DB_TABLE_FOLDERS.'
         WHERE collection_path = ?
           AND NOT EXISTS (SELECT 1 FROM '.DB_TABLE_FILES.' WHERE collection_path LIKE ? LIMIT 1)'
    );

    $mode = '';
    $source = '';
    $sidFiles = [];

    foreach ($lines as $lineNumber => $rawLine) {
        $line = preg_replace('/\s+/', ' ', trim($rawLine));

        if (stripos($line, 'Cleaning Up') !== false) {
            output();
            output('Reached the "Cleaning Up" section at line '.($lineNumber + 1).'; update parsing stopped.');
            break;
        }

        if ($line === 'MOVE' || $line === 'DELETE') {
            $mode = $line;
            $source = '';
            $sidFiles = [];
            output();
            output($mode);
            continue;
        }

        if (in_array($line, IGNORED_COMMANDS, true)) {
            $mode = '';
            $source = '';
            $sidFiles = [];
            continue;
        }

        if ($mode === 'MOVE') {
            if (str_starts_with($line, '#')) {
                $sidFiles = array_values(array_unique(array_merge($sidFiles, extractSidNames($line))));
                continue;
            }

            if (!isHvscPathLine($line)) {
                continue;
            }

            $path = normalizeHvscPath($line);
            if ($source === '') {
                $source = $path;
                continue;
            }

            $destination = $path;
            $sourceIsSid = str_ends_with(strtolower($source), '.sid');
            $destinationIsSid = str_ends_with(strtolower($destination), '.sid');
            $sourceIsUpdate = str_starts_with(strtolower($source), 'update/');

            if ($sourceIsUpdate && !$destinationIsSid) {
                output('New entries:');
                ensureFolder($db, $destination, $hvscVersion);

                foreach ($sidFiles as $sid) {
                    $expectedPath = $destination.$sid;
                    $physicalPath = findPhysicalSidPath($destination, $sid);

                    if ($physicalPath === null) {
                        output(
                            '    WARNING: Could not uniquely match physical SID file: '.$expectedPath.
                            '; skipped.',
                            '#c60'
                        );
                        continue;
                    }

                    if ($physicalPath !== $expectedPath) {
                        output(
                            '    Corrected from update script: '.
                            $expectedPath.
                            ' => '.
                            $physicalPath,
                            '#c60'
                        );
                    }                    

                    $collectionPath = HVSC_PATH.$physicalPath;

                    $insertFile->execute([
                        $collectionPath,
                        $hvscVersion,
                        $collectionPath
                    ]);

                    if ($insertFile->rowCount()) {
                        output('    Added: '.$collectionPath);
                    } else {
                        output('    Already exists: '.$collectionPath);
                    }
                }

                if (!$sidFiles) {
                    output('    WARNING: No .sid filenames were found in the preceding comments.', '#c60');
                }
            } elseif ($sourceIsSid && $destinationIsSid) {
                $sourcePath = HVSC_PATH.$source;
                $destinationPath = HVSC_PATH.$destination;

                $findFile->execute([$sourcePath]);
                $sourceId = $findFile->fetchColumn();

                $findFile->execute([$destinationPath]);
                $destinationId = $findFile->fetchColumn();

                if ($sourceId === false && $destinationId !== false) {
                    output(
                        'Rename already applied: '.$source.' => '.$destination.
                        ' (destination ID '.$destinationId.')'
                    );
                } elseif ($sourceId !== false && $destinationId === false) {
                    $renameFile->execute([
                        $destinationPath,
                        $hvscVersion,
                        $sourcePath
                    ]);

                    output(
                        'Renamed file: '.$source.' => '.$destination.
                        ' ('.$renameFile->rowCount().' row)'
                    );
                } elseif ($sourceId === false && $destinationId === false) {
                    output(
                        'WARNING: Rename source and destination are both missing: '.
                        $source.' => '.$destination,
                        '#c60'
                    );
                } else {
                    output(
                        'WARNING: Rename source and destination both exist: '.
                        $source.' [ID '.$sourceId.'] => '.
                        $destination.' [ID '.$destinationId.']; skipped.',
                        '#c60'
                    );
                }
            } elseif ($sourceIsSid && !$destinationIsSid) {
                ensureFolder($db, $destination, $hvscVersion);

                $filename = basename($source);
                $target = $destination.$filename;

                $sourcePath = HVSC_PATH.$source;
                $targetPath = HVSC_PATH.$target;

                $findFile->execute([$sourcePath]);
                $sourceId = $findFile->fetchColumn();

                $findFile->execute([$targetPath]);
                $targetId = $findFile->fetchColumn();

                if ($sourceId === false && $targetId !== false) {
                    output(
                        'Move already applied: '.$source.' => '.$target.
                        ' (destination ID '.$targetId.')'
                    );
                } elseif ($sourceId !== false && $targetId === false) {
                    $renameFile->execute([
                        $targetPath,
                        $hvscVersion,
                        $sourcePath
                    ]);

                    output(
                        'Moved file: '.$source.' => '.$target.
                        ' ('.$renameFile->rowCount().' row)'
                    );
                } elseif ($sourceId === false && $targetId === false) {
                    output(
                        'WARNING: Move source and destination are both missing: '.
                        $source.' => '.$target,
                        '#c60'
                    );
                } else {
                    output(
                        'WARNING: Move source and destination both exist: '.
                        $source.' [ID '.$sourceId.'] => '.
                        $target.' [ID '.$targetId.']; skipped.',
                        '#c60'
                    );
                }                
            } elseif (!$sourceIsSid && !$destinationIsSid) {
                $oldPrefix = HVSC_PATH.$source;
                $newPrefix = HVSC_PATH.$destination;

                $selectFolderFiles->execute([$oldPrefix.'%']);
                $moved = 0;
                foreach ($selectFolderFiles->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $suffix = substr($row['collection_path'], strlen($oldPrefix));
                    $updateFilePath->execute([$newPrefix.$suffix, $hvscVersion, $row['id']]);
                    $moved += $updateFilePath->rowCount();
                }

                $renameFolder->execute([
                    rtrim($newPrefix, '/'),
                    rtrim($oldPrefix, '/')
                ]);
                output('Renamed folder: '.$source.' => '.$destination.' ('.$moved.' file rows)');
            } else {
                output('ERROR: Unknown MOVE pair at line '.($lineNumber + 1).': '.$source.' => '.$destination, '#f00');
            }

            $source = '';
            $sidFiles = [];
            continue;
        }

        if ($mode === 'DELETE' && isHvscPathLine($line)) {
            $target = normalizeHvscPath($line);
            if (str_starts_with(strtolower($target), 'update/')) {
                continue;
            }

            if (str_ends_with(strtolower($target), '.sid')) {
                $deleteFile->execute([HVSC_PATH.$target]);
                output('Deleted file: '.$target.' ('.$deleteFile->rowCount().' row)');
            } else {
                $folderPath = HVSC_PATH.rtrim($target, '/');
                $deleteEmptyFolder->execute([$folderPath, $folderPath.'/%']);
                output('Deleted empty folder row: '.$target.' ('.$deleteEmptyFolder->rowCount().' row)');
            }
        }
    }

    removeDuplicateFolders($db);

    if (TEST_MODE) {
        $db->rollBack();

        output();
        output('TEST MODE: All database changes were rolled back.', '#c60');
    } else {
        $db->commit();

        output();
        output('HVSC database update completed successfully.');
    }

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    output('ERROR: '.$e->getMessage(), '#f00');
}
?>