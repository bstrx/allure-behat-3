<?php

namespace Bstrx\Behat3AllureFormatter;

use Behat\Behat\EventDispatcher\Event\AfterFeatureTested;
use Behat\Behat\EventDispatcher\Event\AfterOutlineTested;
use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\AfterStepTested;
use Behat\Behat\EventDispatcher\Event\BeforeFeatureTested;
use Behat\Behat\EventDispatcher\Event\BeforeOutlineTested;
use Behat\Behat\EventDispatcher\Event\BeforeScenarioTested;
use Behat\Behat\EventDispatcher\Event\BeforeStepTested;
use Behat\Behat\Tester\Result\StepResult;
use Behat\Gherkin\Node\FeatureNode;
use Behat\Gherkin\Node\OutlineNode;
use Behat\Gherkin\Node\ScenarioInterface;
use Behat\Gherkin\Node\ScenarioNode;
use Behat\Testwork\Counter\Timer;
use Behat\Testwork\EventDispatcher\Event\AfterExerciseCompleted;
use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeExerciseCompleted;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use Behat\Testwork\Output\Formatter;
use Behat\Testwork\Output\Printer\OutputPrinter;
use Behat\Testwork\Tester\Result\ExceptionResult;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Yandex\Allure\Adapter\Allure;
use Yandex\Allure\Adapter\Annotation\AnnotationManager;
use Yandex\Allure\Adapter\Annotation\AnnotationProvider;
use Yandex\Allure\Adapter\Annotation\Description;
use Yandex\Allure\Adapter\Annotation\Features;
use Yandex\Allure\Adapter\Annotation\Issues;
use Yandex\Allure\Adapter\Annotation\Parameter;
use Yandex\Allure\Adapter\Annotation\Stories;
use Yandex\Allure\Adapter\Annotation\TestCaseId;
use Yandex\Allure\Adapter\Event\StepCanceledEvent;
use Yandex\Allure\Adapter\Event\StepFailedEvent;
use Yandex\Allure\Adapter\Event\StepFinishedEvent;
use Yandex\Allure\Adapter\Event\StepStartedEvent;
use Yandex\Allure\Adapter\Event\TestCaseBrokenEvent;
use Yandex\Allure\Adapter\Event\TestCaseCanceledEvent;
use Yandex\Allure\Adapter\Event\TestCaseFailedEvent;
use Yandex\Allure\Adapter\Event\TestCaseFinishedEvent;
use Yandex\Allure\Adapter\Event\TestCasePendingEvent;
use Yandex\Allure\Adapter\Event\TestCaseStartedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteFinishedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteStartedEvent;
use Yandex\Allure\Adapter\Model\DescriptionType;
use Yandex\Allure\Adapter\Model\Provider;

use DateTime;

class Behat3AllureFormatter implements Formatter
{
    protected $output;
    protected $name;
    protected $base_path;
    protected $timer;
    protected $exception;
    protected $uuid;
    protected $issue_tag_prefix;
    protected $ignoredTags;
    protected $parameters;
    protected $printer;
    /** @var  \Behat\Testwork\Exception\ExceptionPresenter */
    protected $presenter;

    /** @var  Allure */
    private $lifecycle;

    /**
     * @param $name
     * @param $issue_tag_prefix
     * @param $ignoredTags
     * @param $base_path
     * @param $presenter
     */
    public function __construct($name, $issue_tag_prefix, $ignoredTags, $base_path, $presenter)
    {
        $this->name = $name;
        $this->issue_tag_prefix = $issue_tag_prefix;
        $this->ignoredTags = $ignoredTags;
        $this->base_path = $base_path;
        $this->presenter = $presenter;
        $this->timer = new Timer();
        $this->printer = new Printer();
        $this->parameters = new ParameterBag();
    }

    /**
     * @return Allure
     */
    private function getLifeCycle()
    {
        if (!isset($this->lifecycle)) {
            $this->lifecycle = Allure::lifecycle();
        }
        return $this->lifecycle;
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority),
     * array('methodName2')))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            'tester.exercise_completed.before' => 'onBeforeExerciseCompleted',
            'tester.exercise_completed.after' => 'onAfterExerciseCompleted',
            'tester.suite_tested.before' => 'onBeforeSuiteTested',
            'tester.suite_tested.after' => 'onAfterSuiteTested',
            'tester.feature_tested.before' => 'onBeforeFeatureTested',
            'tester.feature_tested.after' => 'onAfterFeatureTested',
            'tester.scenario_tested.before' => 'onBeforeScenarioTested',
            'tester.scenario_tested.after' => 'onAfterScenarioTested',
            'tester.outline_tested.before' => 'onBeforeOutlineTested',
            'tester.outline_tested.after' => 'onAfterOutlineTested',
            'tester.step_tested.before' => 'onBeforeStepTested',
            'tester.step_tested.after' => 'onAfterStepTested'
        ];
    }

    /**
     * Returns formatter name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns formatter description.
     *
     * @return string
     */
    public function getDescription()
    {
        return "Allure formatter for Behat 3";
    }

    /**
     * Returns formatter output printer.
     *
     * @return OutputPrinter
     */
    public function getOutputPrinter()
    {
        return $this->printer;
    }

    /**
     * Sets formatter parameter.
     *
     * @param string $name
     * @param mixed $value
     */
    public function setParameter($name, $value)
    {
        $this->parameters->set($name, $value);
    }

    /**
     * Returns parameter name.
     *
     * @param string $name
     *
     * @return mixed
     */
    public function getParameter($name)
    {
        return $this->parameters->get($name);
    }

    /**
     * @param BeforeExerciseCompleted $event
     */
    public function onBeforeExerciseCompleted(BeforeExerciseCompleted $event)
    {

    }

    /**
     * @param AfterExerciseCompleted $event
     */
    public function onAfterExerciseCompleted(AfterExerciseCompleted $event)
    {

    }

    /**
     * @param BeforeSuiteTested $event
     */
    public function onBeforeSuiteTested(BeforeSuiteTested $event)
    {
        AnnotationProvider::addIgnoredAnnotations([]);
        $this->prepareOutputDirectory(
            $this->printer->getOutputPath()
        );
        $now = new DateTime();
        $start_event = new TestSuiteStartedEvent(sprintf('TestSuite-%s', $now->format('Y-m-d_His')));

        $this->uuid = $start_event->getUuid();

        $this->getLifeCycle()->fire($start_event);
    }

    /**
     * @param AfterSuiteTested $event
     */
    public function onAfterSuiteTested(AfterSuiteTested $event)
    {
        AnnotationProvider::registerAnnotationNamespaces();
        $this->getLifeCycle()->fire(new TestSuiteFinishedEvent($this->uuid));

    }

    /**
     * @param BeforeFeatureTested $event
     */
    public function onBeforeFeatureTested(BeforeFeatureTested $event)
    {

    }

    /**
     * @param AfterFeatureTested $event
     */
    public function onAfterFeatureTested(AfterFeatureTested $event)
    {

    }

    /**
     * @param BeforeScenarioTested $event
     */
    public function onBeforeScenarioTested(BeforeScenarioTested $event)
    {
        /** @var ScenarioNode $scenario */
        $scenario = $event->getScenario();
        /** @var \Behat\Gherkin\Node\FeatureNode $feature */
        $feature = $event->getFeature();


        $annotations = array_merge(
            $this->parseFeatureAnnotations($feature),
            $this->parseScenarioAnnotations($scenario)
        );

        $annotationManager = new AnnotationManager($annotations);
        $scenarioName = sprintf('%s:%d', $feature->getFile(), $scenario->getLine());
        $scenarioEvent = new TestCaseStartedEvent($this->uuid, $scenarioName);
        $annotationManager->updateTestCaseEvent($scenarioEvent);

        $this->getLifeCycle()->fire($scenarioEvent->withTitle($scenario->getTitle()));
    }

    /**
     * @param AfterScenarioTested $event
     */
    public function onAfterScenarioTested(AfterScenarioTested $event)
    {
        $this->processScenarioResult($event->getTestResult());
    }

    /**
     * @param BeforeOutlineTested $event
     */
    public function onBeforeOutlineTested(BeforeOutlineTested $event)
    {
        static $outlineCounter = 0;
        /** @var OutlineNode $outline */
        $outline = $event->getOutline();
        $feature = $event->getFeature();

        $scenarioName = sprintf(
            '%s:%d [%d]',
            $feature->getFile(),
            $outline->getLine(),
            $outlineCounter
        );

        $feature->getScenarios();
        $scenarioEvent = new TestCaseStartedEvent($this->uuid, $scenarioName);
        $annotations = array_merge(
            $this->parseFeatureAnnotations($event->getFeature()),
            $this->parseScenarioAnnotations($outline),
            $this->parseExampleAnnotations($outline)
        );
        $annotationManager = new AnnotationManager($annotations);
        $annotationManager->updateTestCaseEvent($scenarioEvent);
        $this->getLifeCycle()->fire($scenarioEvent->withTitle($outline->getTitle()));
    }

    /**
     * @param AfterOutlineTested $event
     */
    public function onAfterOutlineTested(AfterOutlineTested $event)
    {
        $this->processScenarioResult($event->getTestResult());
    }

    /**
     * @param BeforeStepTested $event
     */
    public function onBeforeStepTested(BeforeStepTested $event)
    {
        $step = $event->getStep();
        $stepEvent = new StepStartedEvent($step->getText());
        $stepEvent->withTitle(sprintf('%s %s', $step->getType(), $step->getText()));

        $this->getLifeCycle()->fire($stepEvent);
    }

    /**
     * @param AfterStepTested $event
     */
    public function onAfterStepTested(AfterStepTested $event)
    {
        $result = $event->getTestResult();
        if ($result instanceof ExceptionResult && $result->hasException()) {
            $this->exception = $result->getException();
        }
        switch ($event->getTestResult()->getResultCode()) {
            case StepResult::FAILED:
                $this->addFailedStep();
                break;
            case StepResult::UNDEFINED:
                $this->addFailedStep();
                break;
            case StepResult::PENDING:
            case StepResult::SKIPPED:
                $this->addCancelledStep();
                break;
            case StepResult::PASSED:
            default:
                $this->exception = null;
        }
        $this->addFinishedStep();
    }

    /**
     * @param $outputDirectory
     */
    protected function prepareOutputDirectory($outputDirectory)
    {
        if (!file_exists($outputDirectory)) {
            mkdir($outputDirectory, 0755, true);
        }

        if (is_null(Provider::getOutputDirectory())) {
            Provider::setOutputDirectory($outputDirectory);
        }
    }

    /**
     * @param FeatureNode $featureNode
     * @return array
     */
    protected function parseFeatureAnnotations(FeatureNode $featureNode)
    {
        $feature = new Features();
        $feature->featureNames = [$featureNode->getTitle()];
        $description = new Description();
        $description->type = DescriptionType::TEXT;
        $description->value = $featureNode->getDescription();

        return [$feature, $description];
    }

    /**
     * @param ScenarioInterface $scenarioNode
     * @return array
     */
    protected function parseScenarioAnnotations(ScenarioInterface $scenarioNode)
    {
        $annotations = [];
        $story = new Stories();
        $story->stories = [];

        $issues = new Issues();
        $issues->issueKeys = [];

        $testId = new TestCaseId();
        $testId->testCaseIds = [];

        $ignoredTags = [];

        if (is_string($this->ignoredTags)) {
            $ignoredTags = array_map('trim', explode(',', $this->ignoredTags));
        } elseif (is_array($this->ignoredTags)) {
            $ignoredTags = $ignoredTags;
        }
        foreach ($scenarioNode->getTags() as $tag) {
            if ($this->issue_tag_prefix) {
                if (stripos($tag, $this->issue_tag_prefix) === 0) {
                    $issues->issueKeys[] = $tag;
                    continue;
                }
            }

            $story->stories[] = $tag;
        }

        if ($story->getStories()) {
            array_push($annotations, $story);
        }
        if ($issues->getIssueKeys()) {
            array_push($annotations, $issues);
        }
        if ($testId->getTestCaseIds()) {
            array_push($annotations, $testId);
        }

        return $annotations;
    }

    /**
     * @param $result
     */
    protected function processScenarioResult($result)
    {

        if ($result instanceof ExceptionResult && $result->hasException()) {
            $this->exception = $result->getException();
        }

        switch ($result->getResultCode()) {
            case StepResult::FAILED:
                $this->addTestCaseFailed();
                break;
            case StepResult::UNDEFINED:
                $this->addTestCaseBroken();
                break;
            case StepResult::PENDING:
                $this->addTestCasePending();
                break;
            case StepResult::SKIPPED:
                $this->addTestCaseCancelled();
                break;
            case StepResult::PASSED:
            default:
                $this->exception = null;

        }

        $this->addTestCaseFinished();
    }

    /**
     * @param OutlineNode $outline
     * @return array
     */
    protected function parseExampleAnnotations(OutlineNode $outline)
    {
        $parameters = [];

        $examplesRow = $outline->getExampleTable()->getIterator()->current();
        foreach ($examplesRow as $name => $value) {
            $parameter = new Parameter();
            $parameter->name = $name;
            $parameter->value = $value;
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    private function addCancelledStep()
    {
        $this->getLifeCycle()->fire(new StepCanceledEvent());
    }

    private function addFinishedStep()
    {
        $this->getLifeCycle()->fire(new StepFinishedEvent());
    }

    private function addFailedStep()
    {
        $this->getLifeCycle()->fire(new StepFailedEvent());
    }

    private function addTestCaseFinished()
    {
        $this->getLifeCycle()->fire(new TestCaseFinishedEvent());
    }

    private function addTestCaseCancelled()
    {
        $this->getLifeCycle()->fire(new TestCaseCanceledEvent());
    }

    private function addTestCasePending()
    {
        $this->getLifeCycle()->fire(new TestCasePendingEvent());
    }

    private function addTestCaseBroken()
    {
        $this->getLifeCycle()->fire($event = new TestCaseBrokenEvent());
    }

    private function addTestCaseFailed()
    {
        $event = new TestCaseFailedEvent();
        $event->withException($this->exception)
            ->withMessage($this->exception->getMessage());
        $this->getLifeCycle()->fire($event);
    }
}
