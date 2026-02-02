<?php

namespace AnyllmCli\Infrastructure\Terminal;

class TerminalManager {
    private $originalState;

    public function __construct() {
        $this->originalState = shell_exec('stty -g');
    }

    public function setRawMode() {
        shell_exec('stty -icanon -echo');
    }

    public function restoreMode() {
        if ($this->originalState) {
            shell_exec('stty ' . $this->originalState);
        }
        Style::showCursor();
    }
}
