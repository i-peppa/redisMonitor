<?php

$hosts = array(
        array("localhost", "6371"),
);

function run($hosts) {
    $pid = pcntl_fork();
    if ($pid < 0) {
        echo"run failure [couldn't fork]!";
    } else if ($pid > 0) {
        parentAction($hosts);
    } else {
        childAction($hosts);
    }
}

function parentAction($hosts) {
    sleep(2);
    foreach ($hosts as $host) {
        `ps -aux | grep "redis-cli -h $host[0] -p $host[1] monitor" | awk -F " " '{print $2}' | xargs kill > /dev/null 2>&1`;
    }

    foreach ($hosts as $host) {
        (new redisCmdQps())->analysis("log/" . $host[0].':'.$host[1] . '.log');
    }
}

function childAction($hosts) {
    foreach ($hosts as $host) {
        `rm log/$host[0]:$host[1].log > /dev/null 2>&1`;
        `redis-cli -h $host[0] -p $host[1] monitor >> log/$host[0]:$host[1].log &`;
    }
}



class redisCmdQps {

    private $fuck_cmds = array();

    private $fuck_cmd_ips = array();

    private $client_ips = array();

    private $client_ip_cmds = array();

    function analysis($host) {
        $content = file_get_contents($host);
        echo "\n\n======        " . $host . " 结果       ========\n\n";
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $columns = explode(" ", $line);
            $cmd = $columns[3];

            $ip = substr($columns[2], 0, stripos($columns[2], ':'));

            $this->is_fuck_cmd($ip, $columns);

            if ($ip) {
                $this->client_ips[$ip]++;
                if (empty($this->client_ip_cmds[$ip])) {
                    $this->client_ip_cmds[$ip] = '';
                }
                $this->client_ip_cmds[$ip][$cmd]++;
            }
        }

        arsort($this->client_ips);
        $this->client_ips = array_slice($this->client_ips, 0, 8);

        arsort($this->fuck_cmds);
        $this->fuck_cmds = array_slice($this->fuck_cmds, 0 ,8);

        $this->output();

        $this->outputFuckCmd();

    }

    function output() {
        echo "\n----------------------- client ip Qps 由高到低排名前8 -----------------------\n";
        foreach ($this->client_ips as $client_ip => $times) {
            echo "\n";
            $cur_client_ip_cmds  = $this->client_ip_cmds[$client_ip];
            arsort($cur_client_ip_cmds);
            $cur_client_ip_cmds = array_slice($cur_client_ip_cmds, 0, 8);
            echo $client_ip . " * " . $times . " (";
            foreach ($cur_client_ip_cmds as $cur_client_ip_cmd => $cmd_ip_times) {
                echo  $cur_client_ip_cmd . " ** " . $cmd_ip_times . " | ";
            }
            echo ")\n";
        }
    }

    function outputFuckCmd() {
        echo "\n----------------------- client 可能有问题的命令列表 -----------------------\n";
        foreach ($this->fuck_cmds as $cmd => $times) {
            echo "\n";
            $cur_fuck_cmd_ips  = $this->fuck_cmd_ips[$cmd];
            arsort($cur_fuck_cmd_ips);
            $cur_fuck_cmd_ips = array_slice($cur_fuck_cmd_ips, 0, 8);
            echo $cmd . " * " . $times . " \n\n";
            foreach ($cur_fuck_cmd_ips as $cur_fuck_cmd_ip => $times) {
                echo  " ==> " . $cur_fuck_cmd_ip . " ** " . $times . " \n ";
            }
            echo "\n";
        }
        echo "\n\n";
    }



    function is_fuck_cmd($ip, $columns) {

        $cmd = strtolower($columns[3]);

        $cmd = trim($cmd, '"');

        if (stripos($cmd , "range")) {

            $arg_s = trim($columns[5], '"');
            $arg_e = trim($columns[6], '"');

            if (($arg_s == 0 && $arg_e == -1) || ($arg_e - $arg_s) > 1000) {
                $this->fuck_cmds[$cmd.'#'.trim($columns[4], '"').'#'.trim($columns[5], '"').'#'.trim($columns[6], '"')]++;
                $this->fuck_cmd_ips[$cmd.'#'.trim($columns[4], '"').'#'.trim($columns[5], '"').'#'.trim($columns[6], '"')][$ip]++;
            }
        }
        if ($cmd == 'hgetall') {
            $this->fuck_cmds[$cmd.'#'.$columns[4]]++;
            $this->fuck_cmd_ips[$cmd.'#'.$columns[4]][$ip]++;
        }
    }

}


run($hosts);


