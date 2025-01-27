<?php

namespace Symbiote\AdvancedWorkflow\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\View\ArrayData;
use Symbiote\AdvancedWorkflow\Admin\WorkflowDefinitionExporter;
use Symbiote\AdvancedWorkflow\Admin\WorkflowDefinitionImporter;
use Symbiote\AdvancedWorkflow\DataObjects\ImportedWorkflowTemplate;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;
use Symbiote\AdvancedWorkflow\Templates\WorkflowTemplate;
use SilverStripe\ORM\ValidationException;

/**
 * Tests for workflow import/export logic.
 *
 * @author     russell@silverstripe.com
 * @license    BSD License (http://silverstripe.org/bsd-license/)
 * @package    advancedworkflow
 * @subpackage tests
 */
class WorkflowImportExportTest extends SapphireTest
{
    protected static $fixture_file = 'workflowtemplateimport.yml';

    /**
     * Utility method, used in tests
     * @return WorkflowDefinition
     */
    protected function createDefinition()
    {
        $definition = new WorkflowDefinition();
        $definition->Title = "Dummy Workflow Definition";
        $definition->write();

        $stepOne = new WorkflowAction();
        $stepOne->Title = "Step One";
        $stepOne->WorkflowDefID = $definition->ID;
        $stepOne->write();

        $stepTwo = new WorkflowAction();
        $stepTwo->Title = "Step Two";
        $stepTwo->WorkflowDefID = $definition->ID;
        $stepTwo->write();

        $transitionOne = new WorkflowTransition();
        $transitionOne->Title = 'Step One T1';
        $transitionOne->ActionID = $stepOne->ID;
        $transitionOne->NextActionID = $stepTwo->ID;
        $transitionOne->write();

        return $definition;
    }

    /**
     * Create a WorkflowDefinition with some actions. Ensure an expected length of formatted template.
     */
    public function testFormatWithActions()
    {
        $definition = $this->createDefinition();
        $exporter = Injector::inst()->createWithArgs(WorkflowDefinitionExporter::class, array($definition->ID));
        $member = new Member();
        $member->FirstName = 'joe';
        $member->Surname = 'bloggs';
        $exporter->setMember($member);
        $templateData = new ArrayData(array(
            'ExportMetaData' => $exporter->ExportMetaData(),
            'ExportActions' => $exporter->getDefinition()->Actions()
        ));

        $formatted = $exporter->format($templateData);
        $numActions = count(preg_split("#\R#", $formatted));

        $this->assertNotEmpty($formatted);
        // Seems arbitrary, but if no actions, then the resulting YAML file is exactly 18 lines long
        $this->assertGreaterThan(18, $numActions);
    }

    /**
     * Create a WorkflowDefinition with NO actions. Ensure an expected length of formatted template.
     */
    public function testFormatWithoutActions()
    {
        $definition = $this->createDefinition();
        $exporter = Injector::inst()->createWithArgs(WorkflowDefinitionExporter::class, array($definition->ID));
        $member = new Member();
        $member->FirstName = 'joe';
        $member->Surname = 'bloggs';
        $exporter->setMember($member);
        $templateData = new ArrayData(array());

        $formatted = $exporter->format($templateData);
        $numActions = count(preg_split("#\R#", $formatted));

        // Seems arbitrary, but if no actions, then the resulting YAML file is exactly 19 lines long
        $this->assertEquals(19, $numActions);

        // Ensure outputted YAML has no blank lines, where SS's control structures would normally be
        $numBlanks = preg_match("#^\s*$#m", $formatted);
        $this->assertEquals(0, $numBlanks);
    }

    /**
     * Tests a badly formatted YAML import for parsing (no headers)
     */
    public function testParseBadYAMLNoHeaderImport()
    {
        $importer = new WorkflowDefinitionImporter();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid YAML format.');
        $source = <<<'EOD'
SilverStripe\Core\Injector\Injector\Injector:
  ExportedWorkflow:
    class: Symbiote\AdvancedWorkflow\Templates\WorkflowTemplate
    constructor:
      - 'My Workflow 4 20/02/2014 03-12-55'
      - 'Exported from localhost on 20/02/2014 03:12:55 by joe bloggs using SilverStripe versions Framework 4.0.0-beta3'
      - 0.2
      - 0
      - 3
    properties:
      structure:
        'Step One':
          type: Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction
          transitions:
            - Step One T1: 'Step Two'
        'Step Two':
          type: Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction
  Symbiote\AdvancedWorkflow\Services\WorkflowService:
    properties:
      templates:
        - '%$ExportedWorkflow'
EOD;

        $importer->parseYAMLImport($source);
    }

    /**
     * Tests a badly formatted YAML import for parsing (missing YML colon)
     */
    public function testParseBadYAMLMalformedImport()
    {
        $importer = new WorkflowDefinitionImporter();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid YAML format. Unable to parse.');
        $source = <<<'EOD'
---
Name: exportedworkflow
---
# Missing colon on line below
SilverStripe\Core\Injector\Injector
  ExportedWorkflow:
    class: Symbiote\AdvancedWorkflow\Templates\WorkflowTemplate
    constructor:
      - 'My Workflow 4 20/02/2014 03-12-55'
      - 'Exported from localhost on 20/02/2014 03-12-55 by joe bloggs using SilverStripe versions Framework 4.0.0-beta3'
      - 0.2
      - 0
      - 3
    properties:
      structure:
        'Step One'
          type: Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction
          transitions:
            - Step One T1: 'Step Two'
        'Step Two':
          type: Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction
  Symbiote\AdvancedWorkflow\Services\WorkflowService:
    properties:
      templates:
        - '%$ExportedWorkflow'
EOD;

        $importer->parseYAMLImport($source);
    }

    /**
     * Tests a well-formatted YAML import for parsing
     */
    public function testParseGoodYAMLImport()
    {
        $importer = new WorkflowDefinitionImporter();
        $source = <<<'EOD'
---
Name: exportedworkflow
---
SilverStripe\Core\Injector\Injector:
  ExportedWorkflow:
    class: Symbiote\AdvancedWorkflow\Templates\WorkflowTemplate
    constructor:
      - 'My Workflow 4 20/02/2014 03-12-55'
      - 'Exported from localhost on 20/02/2014 03-12-55 by joe bloggs using SilverStripe versions Framework 4.0.0-beta3'
      - 0.2
      - 0
      - 3
    properties:
      structure:
        'Step One':
          type: Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction
          transitions:
            - Step One T1: 'Step Two'
        'Step Two':
          type: Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction
  Symbiote\AdvancedWorkflow\Services\WorkflowService:
    properties:
      templates:
        - '%$ExportedWorkflow'
EOD;

        $this->assertNotEmpty($importer->parseYAMLImport($source));
    }

    /**
     * Given no ImportedWorkflowTemplate fixture/input data, tests an empty array is returned
     * by WorkflowDefinitionImporter#getImportedWorkflows()
     */
    public function testGetImportedWorkflowsNone()
    {
        $this->clearFixtures();
        $importer = new WorkflowDefinitionImporter();
        $imports = $importer->getImportedWorkflows();
        $this->assertEmpty($imports);
    }

    /**
     * Given a single ImportedWorkflowTemplate fixture/input data, tests an non-empty array is returned
     * by WorkflowDefinitionImporter#getImportedWorkflows()
     */
    public function testGetImportedWorkflowsOne()
    {
        $name = 'My Workflow 21/02/2014 09-01-29';
        // Pretend a ImportedWorkflowTemplate object has been created by WorkflowBulkLoader
        $this->objFromFixture(ImportedWorkflowTemplate::class, 'Import01');

        /** @var WorkflowDefinitionImporter $importer */
        $importer = singleton(WorkflowDefinitionImporter::class);
        $import = $importer->getImportedWorkflows($name);

        $this->assertNotEmpty($import);
        $this->assertInstanceOf(WorkflowTemplate::class, $import);
        $this->assertEquals($name, $import->getName());
    }

    /**
     * Given many ImportedWorkflowTemplate fixture/input data, tests an non-empty array is returned
     * by WorkflowDefinitionImporter#getImportedWorkflows()
     */
    public function testGetImportedWorkflowsMany()
    {
        // Pretend some ImportedWorkflowTemplate objects have been created by WorkflowBulkLoader
        $this->objFromFixture(ImportedWorkflowTemplate::class, 'Import02');
        $this->objFromFixture(ImportedWorkflowTemplate::class, 'Import03');

        $importer = singleton(WorkflowDefinitionImporter::class);
        $imports = $importer->getImportedWorkflows();

        $this->assertNotEmpty($imports);
        $this->assertIsArray($imports);
        $this->assertGreaterThan(1, count($imports));
    }
}
