<?php
declare (strict_types = 1);

use parallel\{Channel,Runtime,Events,Events\Event};

$callManagerThread = function(string $scriptId, string $nodeId, string $fritzHost, int $fritzPort, Channel $homegearChannel)
{
    require('fritzbox-callmonitor.classes.php'); //Bootstrapping in Runtime constructor does not work for some reason.

    $hg = new \Homegear\Homegear();
    $callParser = new CallParser();

    if ($hg->registerThread($scriptId) === false)
    {
        $hg->log(2, "fritzbox: Could not register thread.");
        return;
    }

    $events = new Events();
    $events->addChannel($homegearChannel);
    $events->setTimeout(100000);

    $fritzboxSocket = @fsockopen($fritzHost, $fritzPort);

    while (true)
    {
        try
        {
            if($fritzboxSocket)
            {
                $events->setTimeout(100000);
                stream_set_timeout($fritzboxSocket, 10);
                $result = fgets($fritzboxSocket);

                if ($result === false)
                {
                    $hg->log(4, "fritzbox: disconnected.");
                    $fritzboxSocket = false;
                }
                else if ($result != "")
                {
                    $hg->log(4, "fritzbox: $result");
                    $payload = $callParser->parseCallRecord($result);
                    $hg->nodeOutput($nodeId, 0, array('payload' => $payload));
                }
            }
            else
            {

                $events->setTimeout(10000000);
                $hg->log(4, "fritzbox: Trying to reconnect.");
                $fritzboxSocket = @fsockopen($fritzHost, $fritzPort);
                if(!$fritzboxSocket) $hg->log(4, "fritzbox: Could not connect.");
                else $hg->log(4, "fritzbox: connected.");
            }

            $breakLoop = false;
            $event = NULL;
            do
            {
                $event = $events->poll();
                if($event)
                {
                    if($event->source == 'mainHomegearChannelNode'.$nodeId)
                    {
                        $events->addChannel($homegearChannel);
                        if($event->type == Event\Type::Read)
                        {
                            if(is_array($event->value) && count($event->value) > 0)
                            {
                                if($event->value['name'] == 'stop') $breakLoop = true; //Stop
                            }
                        }
                        else if($event->type == Event\Type::Close) $breakLoop = true; //Stop
                    }
                }

                if($breakLoop) break;
            }
            while($event);

            if($breakLoop) break;
        }
        catch(Events\Error\Timeout $ex)
        {
        }
    }
    if($fritzboxSocket !== false) fclose($fritzboxSocket);
};

class HomegearNode extends HomegearNodeBase
{
    private $hg = NULL;
    private $nodeInfo = NULL;
    private $mainRuntime = NULL;
    private $mainFuture = NULL;
    private $mainHomegearChannel = NULL; //Channel to pass Homegear events to main thread

    public function __construct()
    {
        $this->hg = new \Homegear\Homegear();
    }

    public function __destruct()
    {
        $this->stop();
        $this->waitForStop();
    }
    
    public function init(array $nodeInfo) : bool
    {
        $this->nodeInfo = $nodeInfo;
        return true;
    }
    
    public function start(): bool
    {
        $scriptId = $this->hg->getScriptId();
        $nodeId = $this->nodeInfo['id'];
        $fritzHost = $this->nodeInfo['info']['fritzbox'];
        $fritzPort = intval($this->nodeInfo['info']['port'] ?? 1012);

        $this->mainRuntime = new Runtime();
        $this->mainHomegearChannel = Channel::make('mainHomegearChannelNode'.$nodeId, Channel::Infinite);

        global $callManagerThread;
        $this->mainFuture = $this->mainRuntime->run($callManagerThread, [$scriptId, $nodeId, $fritzHost, $fritzPort, $this->mainHomegearChannel]);

        return true;
    }
    
    public function stop()
    {
        if($this->mainHomegearChannel) $this->mainHomegearChannel->send(['name' => 'stop', 'value' => true]);
    }
    
    public function waitForStop()
    {
        if($this->mainFuture)
        {
            $this->mainFuture->value();
            $this->mainFuture = NULL;
        }

        if($this->mainHomegearChannel)
        {
            $this->mainHomegearChannel->close();
            $this->mainHomegearChannel = NULL;
        }

        if($this->mainRuntime)
        {
            $this->mainRuntime->close();
            $this->mainRuntime = NULL;
        }
    }
}
