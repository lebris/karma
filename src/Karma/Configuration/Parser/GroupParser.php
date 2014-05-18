<?php

namespace Karma\Configuration\Parser;

class GroupParser extends AbstractSectionParser
{
    private
        $groups,
        $currentLineNumber;
    
    public function __construct()
    {
        $this->groups = array();
        $this->currentLineNumber = -1;
    }
    
    public function parse($line, $lineNumber)
    {
        if($this->isACommentLine($line))
        {
            return true;
        }

        $this->currentLineNumber = $lineNumber;
        $line = trim($line);
        
        if(preg_match('~(?P<groupName>[^=]+)\s*=\s*\[(?P<envList>[^\[\]]+)\]$~', $line, $matches))
        {
            return $this->processGroupDefinition($matches['groupName'], $matches['envList']);
        }
        
        throw new \RuntimeException(sprintf(
            'Syntax error in %s line %d : %s',
            $this->currentFilePath,
            $lineNumber,
            $line
        ));
    }
    
    private function processGroupDefinition($groupName, $envList)
    {
        $groupName = trim($groupName);
        
        $this->checkGroupStillNotExists($groupName);
        
        $environments = array_map('trim', explode(',', $envList));
        $this->checkEnvironmentAreUnique($groupName, $environments);
        
        $this->groups[$groupName] = array();
        
        foreach($environments as $env)
        {
            if(empty($env))
            {
                throw new \RuntimeException(sprintf(
                   'Syntax error in %s line %d : empty environment in declaration of group %s',
                    $this->currentFilePath,
                    $this->currentLineNumber,
                    $groupName
                ));
            }
            
            $this->groups[$groupName][] = $env;
        }
    }
    
    private function checkGroupStillNotExists($groupName)
    {
        if(isset($this->groups[$groupName]))
        {
            throw new \RuntimeException(sprintf(
                'Syntax error in %s line %d : group %s has already been declared',
                $this->currentFilePath,
                $this->currentLineNumber,
                $groupName
            ));
        }
    }
    
    private function checkEnvironmentAreUnique($groupName, array $environments)
    {
        if($this->hasDuplicatedValues($environments))
        {
            throw new \RuntimeException(sprintf(
               'Syntax error in %s line %d : duplicated environment in group %s',
                $this->currentFilePath,
                $this->currentLineNumber,
                $groupName
            ));
        }
    }
    
    private function hasDuplicatedValues(array $values)
    {
        $duplicatedValues = array_filter(array_count_values($values), function ($counter) {
            return $counter !== 1;
        });
        
        return empty($duplicatedValues) === false;
    }
    
    public function getCollectedGroups()
    {
        return $this->groups;
    }
    
    public function postParse()
    {
        $this->checkEnvironmentsBelongToOnlyOneGroup();
        $this->checkGroupsAreNotPartsOfAnotherGroups();
    }
    
    private function checkEnvironmentsBelongToOnlyOneGroup()
    {
        $allEnvironments = $this->getAllEnvironmentsBelongingToGroups();

        if($this->hasDuplicatedValues($allEnvironments))
        {
            throw new \RuntimeException('Error : some environments are in various groups');
        }
    }
    
    private function getAllEnvironmentsBelongingToGroups()
    {
        $allEnvironments = array();
        
        foreach($this->groups as $group)
        {
            $allEnvironments = array_merge($allEnvironments, $group);
        }
        
        return $allEnvironments;
    }
    
    private function checkGroupsAreNotPartsOfAnotherGroups()
    {
        $allEnvironments = $this->getAllEnvironmentsBelongingToGroups();

        $errors = array_intersect($allEnvironments, array_keys($this->groups));
        
        if(! empty($errors))
        {
            throw new \RuntimeException(sprintf(
               'Error : a group can not be part of another group (%s)',
                implode(', ', $errors)
            ));
        }
    }
}