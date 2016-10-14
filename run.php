<?php
require __DIR__ . '/vendor/autoload.php';

// TODO: Add repeat to task

if (empty($argv[1])) {
    die("Usage: php run.php [path to OmniFocus backup.zip]");
}

$reader = TodoMove\Reader\OmnifocusReader::loadBackup(
   $argv[1]
);

$tags = $reader->tags();
$folders = $reader->folders();
$projects = $reader->projects();
$tasks = $reader->tasks();

function outputFolder(\TodoMove\Intercessor\ProjectFolder $folder, $indentation=0) {
	echo str_repeat("\t", $indentation) . "{$folder->name()} (folder)" . PHP_EOL;
    $indentation++;
    foreach ($folder->projects() as $project) {
        echo str_repeat("\t", $indentation) . $project->name() . ' (project)'. PHP_EOL;
        foreach ($project->tasks() as $task) {
            echo str_repeat("\t", $indentation+1) . $task->title() . ' (task)' . PHP_EOL;
        }
    }

    $indentation = 1;

	foreach ($folder->children() as $childFolder) {
		outputFolder($childFolder, $indentation);
	}
}

foreach ($folders as $folderId => $folder) {
	if (is_null($folder->parent())) { // It's not a child, it's a root folder
		outputFolder($folder);
	}
}