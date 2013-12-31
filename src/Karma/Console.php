<?php

namespace Karma;

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