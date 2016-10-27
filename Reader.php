<?php
namespace TodoMove\Service\Omnifocus;

use TodoMove\Intercessor\Project;
use TodoMove\Intercessor\ProjectFolder;
use TodoMove\Intercessor\Repeat;
use TodoMove\Intercessor\Tag;
use TodoMove\Intercessor\Tags;
use TodoMove\Intercessor\Task;
use TodoMove\Intercessor\Service\AbstractReader;

class Reader extends AbstractReader
{
    private $xml = []; // Array from (array) simplexml_load_*
    private $idMap = [ // Map of OmniFocus ids to Intercessor ids.  Key is omnifocus id
        'tags' => [],
        'projects' => [],
        'folders' => [],
        'tasks' => [],
    ];

    /**
     * OmnifocusReader constructor.
     *
     * @param array $xml - This should be the result of a simplexml_load_* call
     */
    public function __construct($xml)
    {
        $this->name('Omnifocus');
        $this->xml = is_array($xml) ? $xml : (array) $xml;

        $this->parseTags(); // Must be first as Projects/Tasks utilise them
        $this->parseFolders(); // Folders before projects, as we attach the projects to the folders
        $this->parseProjects(); // This attaches projects to folders
        $this->parseTasks(); // This attaches tasks to projects

        $this->xml = [];
    }

    /**
     * @return $this
     */
    public function parseTags()
    {
        $this->tags = [];

        foreach ($this->xml['context'] as $context) {
            $contextId = (string)$context->attributes()['id'];

            $tag = new Tag((string) $context->name);
            $this->idMap['tags'][$contextId] = $tag->id();
            $this->addTag($tag);
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function parseProjects()
    {
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
                    $projectTags->add($this->tag($this->idMap['tags'][$contextId]));
                }
            }

            $project = new Project( (string) $xmlProject->name);
            $project->status($status);
            $project->tags($projectTags);

            if (!empty((string)$xmlProject->{'repetition-rule'})) {
                $repetition = (string) $xmlProject->{'repetition-rule'};
                $rule = new \Recurr\Rule($repetition, new \DateTime(null, new \DateTimeZone('UTC')), null, 'UTC');
                $rule->setCount(2);

                preg_match('/FREQ=(?<freq>[A-Z]+)/', $repetition, $matches);
                $freq = strtoupper($matches['freq']);

                $repeat = new Repeat();
                $repeat->interval($rule->getInterval());

                // We only support basic options for the minute (not specific week days for example)
                switch ($freq) {
                    case 'DAILY':
                        $type = Repeat::DAY;
                        break;
                    case 'WEEKLY':
                        $type = Repeat::WEEK;
                        break;
                    case 'MONTHLY':
                        $type = Repeat::MONTH;
                        break;
                    case 'YEARLY':
                        $type = Repeat::YEAR;
                        break;
                    default:
                        throw new \Exception('Repetition-rule frequency not supported: ' . $freq);
                }

                $repeat->type($type);

                $project->repeat($repeat);
            }



            $this->addProject($project);
            $this->idMap['projects'][$projectId] = $project->id();
            $this->folder($this->idMap['folders'][$folderId])->project($project);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function parseFolders()
    {
        $this->folders = [];

        foreach ($this->xml['folder'] as $xmlFolder) {
            if ((bool)$xmlFolder->hidden === true) {
                //continue;
            }

            $folder = new ProjectFolder((string) $xmlFolder->name);
            $folderId = (string)$xmlFolder->attributes()['id'];
            $this->idMap['folders'][$folderId] = $folder->id();

            $this->addFolder($folder);
        }

        foreach ($this->xml['folder'] as $xmlFolder) {
            if ((bool)$xmlFolder->hidden === true) {
                //continue;
            }

            if (array_key_exists('folder', $xmlFolder)) { // It is a child and has a parent
                $parentFolderId = (string) $xmlFolder->folder->attributes()['idref'];
                $folderId = (string)$xmlFolder->attributes()['id'];

                $folder = $this->folder($this->idMap['folders'][$folderId]);
                $folder->parent($this->folder($this->idMap['folders'][$parentFolderId]));

                $this->folder($this->idMap['folders'][$parentFolderId])->child($folder);
            }
        }

        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function parseTasks()
    {
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
                    $taskTags->add($this->tag($this->idMap['tags'][$contextId]));
                }
            }

            $task = (new Task((string)$xmlTask->name))
                ->created(new \DateTime((string)$xmlTask->added))
                ->flagged((bool) $xmlTask->flagged)
                ->tags($taskTags);

            $this->idMap['tasks'][$taskId] = $task->id();

            if (!empty((string) $xmlTask->start)) {
                $task->defer(new \DateTime((string) $xmlTask->start));
            }

            if (!empty((string) $xmlTask->due)) {
                $task->due(new \DateTime((string) $xmlTask->due));
            }

            if (!empty($xmlTask->note)) {
                $task->notes(trim($this->buildNotes((array) $xmlTask->note)));
            }

            if (!empty((string)$xmlTask->{'repetition-rule'})) {
                $repetition = (string) $xmlTask->{'repetition-rule'};
                $rule = new \Recurr\Rule($repetition, new \DateTime(null, new \DateTimeZone('UTC')), null, 'UTC');
                $rule->setCount(2);

                preg_match('/FREQ=(?<freq>[A-Z]+)/', $repetition, $matches);
                $freq = strtoupper($matches['freq']);

                $repeat = new Repeat();
                $repeat->interval($rule->getInterval());

                // We only support basic options for the minute (not specific week days for example)
                switch ($freq) {
                    case 'DAILY':
                        $type = Repeat::DAY;
                        break;
                    case 'WEEKLY':
                        $type = Repeat::WEEK;
                        break;
                    case 'MONTHLY':
                        $type = Repeat::MONTH;
                        break;
                    case 'YEARLY':
                        $type = Repeat::YEAR;
                        break;
                    default:
                        throw new \Exception('Repetition-rule frequency not supported: ' . $freq);
                }

                $repeat->type($type);

                $task->repeat($repeat);
            }

            if (!empty($projectId)) {
                $task->project($this->project($this->idMap['projects'][$projectId]));
                $this->project($this->idMap['projects'][$projectId])->task($task);
            }

            $this->addTask($task);
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
            Throw new \InvalidArgumentException('Filename is empty');
        }

        if (!is_readable($filename)) {
            Throw new \InvalidArgumentException('File is not readable: ' . $filename);
        }

        $xml = simplexml_load_file($filename);
        if ($xml === false) {
            Throw new \InvalidArgumentException('File is not XML: ' . $filename);
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
     * OmniFocus backup .zip from inside the backup: File->Export->File Format->Backup Documents (Omnifocus).
     * ~/Downloads/OmniFocus.ofocus-backup/00000000000000=fQ_pq1_r7Jz+xxxxx-xxxxx.zip
     *
     * @param string $filename
     * @throws \Exception
     *
     * @returns OmnifocusBackupReader
     */
    public static function loadBackup($filename = 'OmniFocus.zip')
    {
        if (empty($filename)) {
            Throw new \InvalidArgumentException('Filename is empty');
        }

        if (!is_readable($filename)) {
            Throw new \InvalidArgumentException('File is not readable: ' . $filename);
        }

        if (! class_exists('ZipArchive')) {
            Throw new \Exception('ZipArchive is not installed');
        }

        $zip = new \ZipArchive;
        if ($zip->open($filename) === TRUE) {
            $xmlString = $zip->getFromName('contents.xml');
            if (empty($xmlString)) {
                Throw new \LogicException('This isn\'t a valid OmniFocus backup .zip file.  It is missing contents.xml');
            }
            $zip->close();
        } else {
            Throw new \Exception('Failed to open ZipArchive: ' . $filename);
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