<?php

class CollectMetrics extends BuildTask
{

    /**
     * Implement this method in the task subclass to
     * execute via the TaskRunner
     *
     * @param SS_HTTPRequest $request
     */
    public function run($request)
    {
        $result = ModuleMetrics::inst()->toJson();
        
    }
}