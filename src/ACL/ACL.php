<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2018, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

namespace Wedeto\Auth\ACL;

use Wedeto\DB\ACLRule;

/**
 * A RuleLoader that loads rules from the database using the Model\ACLRule class;
 */
class ACL
{
    /** Mapping of entity names to class names */
    protected $classes = [];
    
    /** Mapping of class names to entity names */
    protected $classes_names = [];

    /** The default policy when no policy applies */
    protected $default_policy = Rule::DENY;

    /** Which policy has preference when you conflicting Rule's exist */
    protected $preferred_policy = Rule::ALLOW;

    /** Validator used to validate actions */
    protected $action_validator = null;

    /** The retrieved roles and entities */
    protected $hierarchy = [];

    /** The instance of a rule loader */
    protected $rule_loader;

    public function __construct(RuleLoaderInterface $loader)
    {
        $this->setRuleLoader($loader);
    }

    /**
     * Set the instance of a rule loader to load rules
     * @param RuleLoaderInterface $loader The loader to use
     * @return $this Provides fluent interface
     */
    public function setRuleLoader(RuleLoaderInterface $loader)
    {
        $this->rule_loader = $rule_loader;
        return $this;
    }

    /**
     * @return RuleLoaderInterface the rule loader used to load rules.
     */
    public function getRuleLoader()
    {
        return $this->rule_loader;
    }

    /**
     * Register the name of the object class to be used in ACL entity naming.
     *
     * @param string $class The name of the class
     * @param string $name The name of the ACL objects
     */
    public function registerClass(string $class, string $name)
    {
        if (isset($this->classes[$name]))
            throw new \RuntimeException("Cannot register the same name twice");
    
        $this->classes[$name] = $class;
        $this->classes_names[$class] = $name;
    }

    /**
     * Set the action validator used to validate actions on rules.
     */
    public function setActionValidator(ActionValidatorInterface $validator = null)
    {
        $this->action_validator = $validator;
        return $this;
    }

    /**
     * Set the default policy which is applied when no rule
     * specifies a definitive answer.
     * @param $policy scalar Either one of (Rule::ALLOW, Rule::DENY) or one
     *                       of the strings "ALLOW" or "DENY"
     * @throws Exception When an invalid policy is returned
     */
    public function setDefaultPolicy($policy)
    {
        if (is_string($policy))
        {
            $policy = trim(strtoupper($policy));
            if ($policy === "ALLOW")
                $policy = Rule::ALLOW;
            elseif ($policy === "DENY")
                $policy = Rule::DENY;
        }

        if (!($policy === Rule::ALLOW || $policy === Rule::DENY))
            throw new Exception("Default policy should be either Rule::ALLOW or Rule::DENY"); 

        $this->default_policy = $policy;
        return $this;
    }

    /**
     * @return integer The default policy, the policy to apply when no explicity policy is defined
     */
    public function getDefaultPolicy()
    {
        return $this->default_policy;
    }

    /**
     * Set the default policy which is applied when no rule
     * specifies a definitive answer.
     * @param $policy scalar Either one of (Rule::ALLOW, Rule::DENY) or one
     *                       of the strings "ALLOW" or "DENY"
     * @throws Exception When an invalid policy is returned
     */
    public function setPreferredPolicy($policy)
    {
        if (is_string($policy))
        {
            $policy = trim(strtoupper($policy));
            if ($policy === "ALLOW")
                $policy = Rule::ALLOW;
            elseif ($policy === "DENY")
                $policy = Rule::DENY;
        }

        if (!($policy === Rule::ALLOW || $policy === Rule::DENY))
            throw new Exception("Preferred policy should be either Rule::ALLOW or Rule::DENY"); 

        $this->preferred_policy = $policy;
    }

    /**
     * @return integer The preferred policy, the policy to select when several policies are defined.
     */
    public function getPreferredPolicy()
    {
        return $this->preferred_policy;
    }

    /**
     * This method will load a new instance to be used in ACL inheritance
     */
    public function loadByACLID($id)
    {
        $parts = explode("#", $id);
        if (count($parts) !== 2)
            throw new \RuntimeException("Invalid DAO ID: {$id}");
    
        if (!isset(self::$classes[$parts[0]]))
            throw new \RuntimeException("Invalid DAO type: {$parts[0]}");
    
        $classname = self::$classes[$parts[0]];
        $pkey_values = explode("-", $id);
    
        return call_user_func(array($classname, "get"), $pkey_values);
    }

    /**
     * Get an hierarchy element by specifying its ID
     */
    public function getInstance(string $class, $element_id)
    {
        if (!is_a($class, Hierarchy::class, true))
            throw new Exception("Not a subclass of Hierarchy: $class");

        if (is_object($element_id) && get_class($element_id) === $class)
            return $element_id;

        if (!is_scalar($element_id))
            throw new Exception("Element-ID must be a scalar");

        if (!$this->hasInstance($class, $element_id))
        {
            $root = $class::$root;
            if ($element_id === $root)
                $this->hierarchy[$class][$root] = new $class($root);
            else
                throw new Exception("Element-ID '{$element_id}' is unknown for {$ownclass}");
        }

        return $this->hierarchy[$class][$element_id];
    }

    /**
     * @return Hierarchy Instance of element
     */
    public function hasInstance(string $class, $element_id)
    {
        return isset($this->hierarchy[$class][$element_id]);
    }

    /**
     * Set the instance of a specific ID.
     *
     * @param scalar $id The ID to set the instance for
     * @return $this Provides fluent interface
     */
    public function setInstance(Hierarchy $element)
    {
        $class = get_class($element);
        $this->hierarchy[$class][$element->id] = $element;
        return $this;
    }

    /**
     * Load the rules for a specific entity
     *
     * @param string $entity_id The ID to get rules for
     * @return array The loaded rules
     */
    public function loadRules(string $entity_id)
    {
        return $this->rule_loader->loadRules($entity_id);
    }
}
