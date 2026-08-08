<?php
// Increase server limits to handle large extractions
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

$zip = new ZipArchive;
$targetDir = realpath(__DIR__ . '/../_update');

if (!$targetDir) {
    die("<h1>Error</h1><p>The target folder '[deepsid]/php/_update' does not exist. Please create it first.</p>");
}

$zipFile = $targetDir . '/C64Music.zip'; 
$extractPath = $targetDir;

if ($zip->open($zipFile) === TRUE) {
    // 1. Extract files into the _update folder
    $zip->extractTo($extractPath);
    
    echo "<h1>Extraction Successful.</h1>";
    echo "<p>Files extracted to: <strong>" . htmlspecialchars($extractPath) . "</strong></p>";
    
    // Close the zip file connection to free up server memory
    $zip->close();

    // 2. Rename the folder inside the _update folder
    $oldFolderName = $extractPath . '/C64Music';
    $newFolderName = $extractPath . '/_High Voltage SID Collection';

    if (file_exists($oldFolderName) && is_dir($oldFolderName)) {
        // Check if the target folder name already exists to prevent a conflict
        if (!file_exists($newFolderName)) {
            if (rename($oldFolderName, $newFolderName)) {
                echo "<p style='color: green; font-weight: bold;'>Successfully renamed 'C64Music' to '_High Voltage SID Collection'</p>";
            } else {
                echo "<p style='color: red;'>Error: The server failed to rename the folder. Check folder permissions.</p>";
            }
        } else {
            echo "<p style='color: orange;'>Warning: Could not rename because a folder named '_High Voltage SID Collection' already exists in the update directory.</p>";
        }
    } else {
        echo "<p style='color: red;'>Error: The expected folder 'C64Music' was not found in the update folder after extraction.</p>";
    }

} else {
    echo "<h1>Extraction Failed.</h1><p>Could not open the zip file. Checked path: <strong>" . htmlspecialchars($zipFile) . "</strong></p>";
}
?>