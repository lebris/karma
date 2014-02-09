<?php

namespace Karma;

use Karma\VCS\Git;

class Console
{
    private
        $app;
    
    public function __construct(Application $dic)
    {
        $this->app = new \Symfony\Component\Console\Application();
        
        $this->app->add(new Command\Hydrate($dic));
        $this->app->add(new Command\Display($dic));
        $this->app->add(new Command\Diff($dic));
        $this->app->add(new Command\Rollback($dic));
        $this->app->add(new Command\VCS($dic));
    }
        
    public function run()
    {
        $this->app->run();
    }
    
    public function getConsoleApplication()
    {
        return $this->app;
    }
}