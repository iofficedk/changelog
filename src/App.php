<?php
namespace Io\Changelog;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

Class App
{

    private $path = null;
    private $branch = null;
    private $name = null;
    private $since = null;
    private $spinner = array("-","/","|", "\\", "*");
    private $cli = null;
    private $run = true;


    public static function get () : \Io\Changelog\App
    {
        return new self;
    }


    public function __construct ()
    {
        $this->cli = (php_sapi_name() == 'cli');
        $this->path = $this->backToVendor();

        if ($this->path)
        {
            chdir($this->path);

            if (!is_writable($this->path)) $this->error(4);

            $output = null;
            $response = exec('git --version');

            if (!empty($response) && strpos($response, 'git version') !== false)
            {
                if (strpos($response, 'windows')) $this->error(2);
                else
                {
                    $version = trim(str_replace('git version', '', $response));
                    if (preg_match('/\d+.\d+.\d+/i', $version))
                    {
                        // Check version
                    }
                    else $this->error(3);
                }
            }
            else $this->error(1);

            $currentBranch = exec('git rev-parse --abbrev-ref HEAD', $output);
            if (strpos($currentBranch, 'fatal:') !== false) $this->error(4);
            
            $remote = false;
            $branchremote = exec('git branch -a', $output);
            foreach ( $output AS $branch )
            {
                if (strpos($branch, 'remotes/') !== false && strpos($branch, $currentBranch) !== false)
                {
                    $remote = true;
                }
            }

            if (!$remote) $this->error(5);

            $this->branch = trim($currentBranch);
            $this->name = $this->path . '/changelog.' . $this->branch . '.md';
            $this->since = strftime("%Y-%m-01", strtotime("-3 month"));
        }
        else $this->error(4);
    }


    private function error($errornumber)
    {
        $this->path = null;
        $this->branch = null;
        $this->name = null;
        $this->since = null;
        $this->run = false;

        switch ($errornumber)
        {
            case 1: echo "can not get git version ! have you installed git ?\n"; break;
            case 2: echo "windows git version not supported .. sorry \n"; break;
            case 3: echo "git version not supported !\n"; break;
            case 4: echo "can not write changelog file at " . $this->path . " !\n"; break;
            case 5: echo "why do you want to changelog a local branche !\n"; break;
            default : echo "something went wrong\n"; break;
        }
        exit();
    }


    private function backToVendor()
    {
        $packagePath = \Composer\InstalledVersions::getInstallPath('iofficedk/changelog');
        list($docPath, $package) = explode('/vendor', $packagePath);
        if (is_dir($docPath))
        {
            return $docPath;
        }

        return null;
    }


    public function setName ($Name=null) : void
    {
        if (!empty($Name))
        {
            $this->name = $Name . '.md';
        }
    }


    public function setSince($MonthBack=null) : void
    {
        if ((int) $MonthBack && $MonthBack > 0 && $MonthBack < 13)
        {
            $this->since = strftime("%Y-%m-01", strtotime(-($MonthBack) . " month"));
        }
    }


    public function create ()
    {
        $allCommits = $this->allCommits();
        if (is_array($allCommits) && count($allCommits) > 0)
        {
            $commits = $this->toCommit($allCommits);
            $brancheObj = $this->toBranchObj($commits);
            unset( $commits );

            $currentCommits = $this->currentCommits($brancheObj);
            unset( $brancheObj );

            $this->toMD($currentCommits);
        }
    }


    private function allCommits()
    {
        $gitLogCmd[] = 'git';
        $gitLogCmd[] = 'log';
        $gitLogCmd[] = '-r';
        $gitLogCmd[] = '--all';
        $gitLogCmd[] = '--since="' . $this->since . '"';
        $gitLogCmd[] = '--numstat';
        $gitLogCmd[] = '--no-merges';
        $gitLogCmd[] = '--abbrev-commit';

        $process = new Process($gitLogCmd);
        $process->run(function ($type, $buffer)
        {
            if (Process::ERR !== $type)
            {
                if ($this->cli) echo chr(27) .  "[2G" . $this->spinner[random_int(0,4)];
            }
        });

        if ($this->cli) echo chr(27) .  "[0G";

        if ($process->isSuccessful())
        {
            while ($process->isRunning())
            {
                // waiting for process to finish
            }

            return explode("\n", $process->getOutput());
        }
        else
        {
            throw new ProcessFailedException($process);
        }

    }


    private function toCommit($gitOutput)
    {
        $response = array();
        foreach( $gitOutput AS $row )
        {
            $searchCommit = strpos($row, 'commit');
            if ($searchCommit !== false && $searchCommit == 0)
            {
                $commitId = trim(str_replace('commit', '', $row));
                if (!empty($commitId))
                {
                    $key = $commitId;
                }
            }

            if ($key) $response[$key][] = $row;
        }

        return $response;
    }


    private function toBranchObj($allCommits)
    {
        $this->branchObject = new \stdClass();
        $response = array();

        foreach ($allCommits as $key => $commit)
        {
            if ($this->cli) echo chr(27) .  "[2G" . $this->spinner[random_int(0,4)];

            $obj = new \stdClass();
            $obj->branch = null;
            $obj->commitno = 0;

            $branch = exec('git name-rev ' . $key, $result);
            if (!empty($branch))
            {
                $branch = trim(str_replace(array($key, 'remotes/origin/'), '', $branch));
                $commitNo = 0;
                if (!empty($branch) && strpos($branch, '~') !== false)
                {
                    list($branch, $commitNo) = explode('~', $branch);
                }
                $obj->branch = trim($branch);
                $obj->commitno = trim($commitNo);
            }

            $obj->commit = null;
            $obj->author = null;
            $obj->date = null;
            $obj->merge = null;
            $obj->desc = null;
            $obj->files = array();
            $obj->info = array();

            foreach ($commit as $row)
            {
                // if ($this->cli) echo chr(27) .  "[2G" . $this->spinner[random_int(0,4)];

                $posCommit = strpos($row, 'commit');
                $posAuthor = strpos($row, 'Author:');
                $posDate = strpos($row, 'Date:');
                $posMerge = strpos($row, 'Merge:');
                $posDesc = strpos($row, "   ");

                $fileMatch = null;
                preg_match('/(?P<add>\d+)\s(?P<rm>\d+)\s(?P<file>\D+)/', $row, $fileMatch);

                if ($posCommit !== false && $posCommit == 0)
                {
                    $obj->commit = $key;
                }
                elseif ($posAuthor !== false && $posAuthor == 0) $obj->author = trim(str_replace('Author:', '', $row));
                elseif ($posMerge !== false && $posMerge == 0) $obj->merge = ' ' . trim(str_replace('Merge:', '', $row));
                elseif ($posDate !== false && $posDate == 0)
                {
                    $date = trim(str_replace('Date:', '', $row));
                    $obj->date = strftime("%Y-%m-%d %H:%M:%S", strtotime($date));
                }
                elseif ($posDesc !== false && $posDesc == 0 && !empty($row))
                {
                    $obj->desc .= trim($row);
                }
                elseif (count($fileMatch))
                {
                    $obj->files[] = (object) array(
                        'add'  => $fileMatch['add'],
                        'rm'   => $fileMatch['rm'],
                        'file' => $fileMatch['file']
                    );
                }
                elseif (!empty($row))
                {
                    $obj->info[] = $row;
                }
            }

            $response[(string) $key] = $obj;
        }

        if ($this->cli) echo chr(27) .  "[0G";

        return (object) $response;

    }


    private function currentCommits($allBranchObj)
    {
        $gitLogCmd[] = 'git';
        $gitLogCmd[] = 'log';
        $gitLogCmd[] = '-r';
        $gitLogCmd[] = '--first-parent';
        $gitLogCmd[] = 'origin/' . $this->branch;
        $gitLogCmd[] = '--since="' . $this->since . '"';
        $gitLogCmd[] = '--no-merges';
        $gitLogCmd[] = '--abbrev-commit';

        $process = new Process($gitLogCmd);
        $process->run(function ($type, $buffer) {
            if (Process::ERR !== $type)
            {
                if ($this->cli) echo chr(27) .  "[2G" . $this->spinner[random_int(0,4)];
            }
        });
        if ($this->cli) echo chr(27) .  "[0G";

        if ($process->isSuccessful())
        {
            while ($process->isRunning())
            {
                // waiting for process to finish
            }

            $changelog = new \stdClass();

            $gitOutput = explode("\n", $process->getOutput());

            foreach ( $gitOutput as $row )
            {
                $posCommit = strpos($row, 'commit');
                if ($posCommit !== false && $posCommit == 0)
                {
                    $commit = trim(str_replace('commit', '', $row));
                    if (!empty($commit))
                    {
                        if (property_exists($allBranchObj, $commit))
                        {
                            $obj = $allBranchObj->{$commit};
                            if (empty($changelog->{$obj->branch}))
                            {
                                $changelog->{$obj->branch} = array();
                            }

                            $changelog->{$obj->branch}[$obj->commitno] = (object) array(
                                'commit' => $obj->commit,
                                'date' => $obj->date,
                                'author' => $obj->author,
                                'log' => $obj->desc,
                                'files' => $obj->files
                            );
                        }
                    }
                }
            }

            return $changelog;

        }
        else
        {
            throw new ProcessFailedException($process);
        }

    }


    private function toMD($obj)
    {
        if (($md = fopen($this->name, "w")) !== false)
        {
            fwrite($md, "# Changelog " . strftime("%Y-%m-%d %H:%M") . "\n");

            foreach ($obj as $Branch => $commits)
            {
                fwrite($md, "## " . $Branch . "\n");

                foreach ($commits as $no => $commit)
                {
                    fwrite($md, "+ " . ($no + 1) . " :: " . $commit->date . "\n");
                    fwrite($md, "\t* commit " . $commit->commit . "\n");
                    fwrite($md, "\t* " . $commit->author . "\n");
                    fwrite($md, "\t* " . $commit->log . "\n");
                    fwrite($md, "\t\t```\n");
                    fwrite($md, "\t\t\n");
                    foreach ($commit->files as $file)
                    {
                        fwrite($md, "\t\t" . $file->file . "\n");
                    }
                    fwrite($md, "\t\t\n");
                    fwrite($md, "\t\t```");
                    fwrite($md, "\n\n");
                }
            }

            fclose($md);
        }
    }

}
