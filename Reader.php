<?php
namespace TodoMove\Service\Omnifocus;

use TodoMove\Intercessor\Project;
use TodoMove\Intercessor\ProjectFolder;
use TodoMove\Intercessor\Tag;
use TodoMove\Intercessor\Tags;
use TodoMove\Intercessor\Task;

// TODO: This should extend/implement a class from TodoMove\Intercessor like Reader
// This will read from Todoist/OmniFocus/Wunderlist/etc, but then how to handle the HTTP requests and passing in info (oauth credentials, filename, etc)?

class Reader
{
    private $xml        = []; // Array from (array) simplexml_load_*
    private $of         = []; // OmniFocus store for XML versions of tasks/tags/projects/folders/etc
    private $tags       = []; // key = tagid
    private $folders    = []; // key = folderid
    private $projects   = []; // key = projectid
    private $tasks      = []; // key = taskid (you can get a group of tasks from projects, so no need to group them by projectid

    /**
     * OmnifocusReader constructor.
     *
     * @param array $xml - This should be the result of a simplexml_load_* call
     */
    public function __construct($xml)
    {
        $this->xml = is_array($xml) ? $xml : (array) $xml;

        $this->parseContexts(); // Must be first as Projects/Tasks utilise them
        $this->parseFolders(); // Folders before projects, as we attach the projects to the folders
        $this->parseProjects(); // This attaches projects to folders
        $this->parseTasks(); // This attaches tasks to projects
    }

    /**
     * @return Tag[]
     */
    public function tags()
    {
        return $this->tags;
    }

    /**
     * @param $contextId - contextId from OmniFocus's XML
     *
     * @return Tag
     */
    public function tag($contextId)
    {
        return $this->tags[$contextId];
    }

    /**
     * @return Task[]
     */
    public function tasks()
    {
        return $this->tasks;
    }

    /**
     * @param $taskId - taskId from OmniFocus's XML
     *
     * @return Task
     */
    public function task($taskId)
    {
        return $this->tasks[$taskId];
    }

    /**
     * @return ProjectFolder[]
     */
    public function folders()
    {
        return $this->folders;
    }

    /**
     * @param $folderId
     *
     * @return ProjectFolder
     */
    public function folder($folderId)
    {
        return $this->folders[$folderId];
    }

    /**
     * @return Project[]
     */
    public function projects()
    {
        return $this->projects;
    }

    /**
     * @param $projectId
     *
     * @return Project
     */
    public function project($projectId)
    {
        return $this->projects[$projectId];
    }

    /**
     * @return $this
     */
    public function parseContexts()
    {
        $this->of['contexts'] = [];
        $this->tags = [];

        foreach ($this->xml['context'] as $context) {
            $contextId = (string)$context->attributes()['id'];
            $this->of['contexts'][$contextId] = $context;

            $tag = new Tag((string) $context->name);
            $this->tags[$contextId] = $tag;
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function parseProjects()
    {
        $this->of['contexts'] = [];
        $this->projects = [];

        // Get tasks, that are actually projects, that are _not_ completed
        $xmlProjectsFiltered = array_filter($this->xml['task'], function($var) {
            return (! empty((array)$var->project) && empty((string)$var->completed));
        });

        foreach ($xmlProjectsFiltered as $xmlProject) {
            $projectId = (string)$xmlProject->attributes()['id'];
            $folderId = (string)$xmlProject->project->folder->attributes()['idref'];

            $xmlProjects[$projectId] = $xmlProject;
            $status = (string)$xmlProject->project->status;
            $status = $status ?: 'active';
            $projectTags = new Tags();

            if ($xmlProject->context) {
                $contextId = (string) $xmlProject->context->attributes()['idref'];
                if (!empty($contextId)) {
                    $projectTags->add($this->tag($contextId));
                }
            }

            $project = new Project( (string) $xmlProject->name);
            $project->status($status);
            $project->tags($projectTags);

            $this->projects[$projectId] = $project;
            $this->folder($folderId)->project($project);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function parseFolders()
    {
        $this->of['folders'] = [];
        $this->folders = [];

        foreach ($this->xml['folder'] as $xmlFolder) {
            if ((bool)$xmlFolder->hidden === true) {
                //continue;
            }

            $folder = new ProjectFolder((string) $xmlFolder->name);
            $folderId = (string)$xmlFolder->attributes()['id'];
            $this->folders[$folderId] = $folder;
        }

        foreach ($this->xml['folder'] as $xmlFolder) {
            if ((bool)$xmlFolder->hidden === true) {
                //continue;
            }

            if (array_key_exists('folder', $xmlFolder)) { // It is a child and has a parent
                $parentFolderId = (string) $xmlFolder->folder->attributes()['idref'];

                $folder = $this->folders[(string)$xmlFolder->attributes()['id']];
                $folder->parent($this->folders[$parentFolderId]);

                $this->folders[$parentFolderId]->child($folder);
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function parseTasks()
    {
        $this->of['tasks'] = [];
        $this->tasks = [];

        // Only return tasks that aren't projects, and aren't completed
        $xmlTasksFiltered = array_filter($this->xml['task'], function($var) {
            return (empty((array)$var->project) && empty((string)$var->completed));
        });

        foreach ($xmlTasksFiltered as $xmlTask) {
            $taskId = (string) $xmlTask->attributes()['id'];
            $projectId = (string) $xmlTask->task->attributes()['idref'];

            $taskTags = new Tags();

            if (property_exists($xmlTask, 'context')) {
                $contextId = (string)$xmlTask->context->attributes()['idref'];
                if (!empty($contextId)) {
                    $taskTags->add($this->tag($contextId));
                }
            }

            $task = (new Task((string)$xmlTask->name))
                ->created(new \DateTime((string)$xmlTask->added))
                ->flagged((bool) $xmlTask->flagged)
                ->tags($taskTags);

            if (!empty((string) $xmlTask->start)) {
                $task->defer(new \DateTime((string) $xmlTask->start));
            }

            if (!empty((string) $xmlTask->due)) {
                $task->due(new \DateTime((string) $xmlTask->due));
            }

            if (!empty($xmlTask->note)) {
                $task->notes(trim($this->buildNotes((array) $xmlTask->note)));
            }

            if (!empty($projectId)) {
                $task->project($this->project($projectId));
                $this->project($projectId)->task($task);
            }

            $this->tasks[$taskId] = $task;
        }

        return $this;
    }

    /**
     * @param string $notes - notes from an OmniFocus task
     * @return string
     */
    private function buildNotes($notes)
    {
        $note = '';

        if (is_string($notes)) {
            return $notes . PHP_EOL;
        }

        if (is_array($notes) || $notes instanceof SimpleXMLElement) {
            foreach ((array) $notes as $item) {
                $note .= $this->buildNotes($item);
            }
        }

        return $note;
    }

    /**
     * Pass in the path to a file from an OmniFocus backup (extracted from the zip) - it's likely called contents.xml
     * @param string $filename
     * @throws InvalidArgumentException
     *
     * @return OmnifocusReader
     */
    public static function loadXML($filename = 'contents.xml')
    {
        if (empty($filename)) {
            Throw new InvalidArgumentException('Filename is empty');
        }

        if (!is_readable($filename)) {
            Throw new InvalidArgumentException('File is not readable: ' . $filename);
        }

        $xml = simplexml_load_file($filename);
        if ($xml === false) {
            Throw new InvalidArgumentException('File is not XML: ' . $filename);
        }

        return new static((array)$xml);
    }

    /**
     * @param string $string - Valid XML, likely from contents.xml of an Omnifocus backup
     *
     * @return OmnifocusReader
     */
    public static function loadString($string = '')
    {
        $xml = simplexml_load_string($string);
        if ($xml === false) {
            Throw new InvalidArgumentException('String is not valid XML: ' . $string);
        }

        return new static((array)$xml);
    }

    /**
     * OmniFocus backup from File->Export->File Format->Backup Documents (Omnifocus).
     * ~/Downloads/OmniFocus.ofocus-backup/00000000000000=fQ_pq1_r7Jz+xxxxx-xxxxx.zip
     *
     * @param string $filename
     * @throws Exception
     *
     * @returns OmnifocusBackupReader
     */
    public static function loadBackup($filename = 'OmniFocus.zip')
    {
        if (empty($filename)) {
            Throw new InvalidArgumentException('Filename is empty');
        }

        if (!is_readable($filename)) {
            Throw new InvalidArgumentException('File is not readable: ' . $filename);
        }

        if (! class_exists('ZipArchive')) {
            Throw new Exception('ZipArchive is not installed');
        }

        $zip = new \ZipArchive;
        if ($zip->open($filename) === TRUE) {
            $xmlString = $zip->getFromName('contents.xml');
            if (empty($xmlString)) {
                Throw new LogicException('This isn\'t a valid OmniFocus backup .zip file.  It is missing contents.xml');
            }
            $zip->close();
        } else {
            Throw new Exception('Failed to open ZipArchive: ' . $filename);
        }

        return static::loadString($xmlString);
    }

    /**
     * @return array
     */
    public function xml()
    {
        return $this->xml;
    }
}