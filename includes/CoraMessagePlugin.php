<?php

trait CoraMessagePlugin {

    private $message = [];

    public function flash($message, $type = 'success') {
        $this->message = ['message' => $message, 'type' => $type];
    }

}
