<?php
declare (strict_types = 1);

class Message
{
    public $type;
    public $id;
    public $timestamp;
    public $caller;
    public $callee;
    public $extension;
    public $duration;

    public function copyEndpoints($msg)
    {
        $this->caller = $msg->caller;
        $this->callee = $msg->callee;
    }

    public function setDuration($msg)
    {
        $this->duration = strtotime($this->timestamp) - strtotime($msg->timestamp);
    }
}

class CallParser
{
	private $connections = [];

    public function __construct()
    {
    }

    public function parseCallRecord($record)
    {
        $columns = explode(";", $record);
        $timestamp = $columns[0];
        $type = $columns[1];
        $id = $columns[2];
        $msg = new Message();
        $msg->id = $id;
        $msg->timestamp = $timestamp;

        switch ($type)
        {
            case "CALL":
                $msg->type = "OUTBOUND";
                $msg->caller = $columns[4];
                $msg->callee = $columns[5];
                $msg->extension = $columns[3];
                $this->connections[$id] = $msg;
                break;
            case "RING":
                $msg->type = "INBOUND";
                $msg->caller = $columns[3];
                $msg->callee = $columns[4];
                $this->connections[$id] = $msg;
                break;
            case "CONNECT":
                $msg->copyEndpoints($this->connections[$id]);
                $msg->type = "CONNECT";
                $msg->extension = $columns[3];
                $this->connections[$id] = $msg;
                break;
            case "DISCONNECT":
                $cnn = $this->connections[$id];
                $msg->copyEndpoints($cnn);
                switch ($cnn->type)
                {
                    case "INBOUND":
                        $msg->type = "MISSED";
                        break;
                    case "CONNECT":
                        $msg->setDuration($cnn);
                        $msg->extension = $cnn->extension;
                        $msg->type = "DISCONNECT";
                        break;
                    case "OUTBOUND":
                        $msg->type = "UNREACHED";
                        break;
                }
                $this->connections[$id] = null;
                break;
        }

        return json_encode($msg);
    }
}