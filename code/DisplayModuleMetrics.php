<?php

class DisplayModuleMetrics extends BuildTask
{

    protected $title = 'Module usage information';

    protected $description = 'Displays usage information related to modules on this website.';

    /**
     * Implement this method in the task subclass to
     * execute via the TaskRunner
     *
     * @param SS_HTTPRequest $request
     */
    public function run($request)
    {
        $result = ModuleMetrics::inst()->toJson();
        echo $result;
    }
}