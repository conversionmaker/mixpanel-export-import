<?php

namespace ConversionMaker\MixpanelImportExport\Command;

use ConversionMaker\TomorrowOneMigration\DirectoryMapping;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Psr\Log\LogLevel;

#[AsCommand(
    name: 'cm:events:import',
    description: 'Execute the migration script.',
)]

class ImportEventsCommand extends Command
{
    private ConsoleLogger $logger;

    private Client $httpClient;

    private string $mixpanelEndpoint;

    private string $importDirectory;

    private string $importFile;

    private Filesystem $filesystem;

    private string $importedDocumentsDirectory;

    private OutputInterface $output;

    protected function configure()
    {
        $this->addArgument('importDirectory', InputArgument::REQUIRED, 'Path to the JSON import file.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->logger = new ConsoleLogger($output);
        $this->httpClient = new Client();

        $this->mixpanelEndpoint = $_ENV['BASE_URL'];
        $this->importDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $input->getArgument('importDirectory');
        $this->filesystem = new Filesystem();
        $this->importedDocumentsDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exported';

        if (!is_dir($this->importedDocumentsDirectory)) {
            $this->filesystem->mkdir($this->importedDocumentsDirectory);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $finder = new Finder();
        $finder->files()->in('/Users/florianwessels/cmai')->sortByName();
        $final = [];

        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                $content = preg_split("/(\r\n|\n|\r)/", $file->getContents());
                foreach ($content as $row) {
                    $data = json_decode($row, true);
                    if ($data === null) {
                        continue;
                    }
                    $event = $data['properties'];
                    unset($event['time'], $event['distinct_id'], $event['$insert_id'], $event['$lib_version'], $event['$mp_api_endpoint'], $event['$mp_api_timestamp_ms'], $event['mp_lib'], $event['mp_processing_time_ms'], $event['success'], $event['score'], $event['words']);
                    $eventKey = $event['input_props'] ?? $event['input_description'];
                    $final[$event['input_type']][$eventKey] = $event;
                }
            }
        }

        foreach ($final as $textType => $content) {
            dump($textType);
            $filesystem = new Filesystem();
            $filesystem->dumpFile($textType, json_encode($content));
        }
//        $export = array_values($final);
//        dump($export[0]);

        return Command::SUCCESS;


        $finder->files()->in($this->importDirectory)->sortByName();
        $this->output = $output;

        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                $this->importFile = $file->getRealPath();
                $this->logger->log(LogLevel::NOTICE, sprintf('Start to import events from "%s"', $this->importFile));
                $this->processEventData($this->loadFile());
            }
        }

        return Command::SUCCESS;
    }

    private function loadFile(): array
    {
        $contents = file_get_contents($this->importFile);
        $events = preg_split('/\r\n|\r|\n/', $contents);

        unset($contents);

        $json = array_map(function ($event) { return json_decode($event, true);}, $events);

        if ($json === null) {
            $this->logger->notice(sprintf('Could not read file "%s"', $this->importFile));
        }

        return $json ?? [];
    }

    private function processEventData(array $dataToImport): void
    {
        $eventCount = 0;
        $mixpanelEvents = [];
        $progressBar = new ProgressBar($this->output, count($dataToImport));
        $progressBar->start();

        foreach ($dataToImport as $data) {
            if ($data === null || $data['properties']['distinct_id'] === '00000000-0000-0000-0000-000000000000') {
                $progressBar->advance();
                continue;
            }

            if ($data['event'] === '$identify' && ($data['properties']['$failure_reason'] ?? false)) {
                continue;
            }

            $insertId = $data['properties']['$inserted_id'] ?? hash('sha256', serialize($data) . $this->importFile);
            $data['properties']['$insert_id'] = $insertId;

            if ($this->markEventAsProcessed($insertId, false)) {
                $progressBar->advance();
                continue;
            }

            $mixpanelEvents[] = $data;
            $this->markEventAsProcessed($insertId);
            $eventCount++;

            if ($eventCount === 1500) {
                // Mixpanel supports only 1 MB of uncompressed JSON or 2000 events a time
                $this->postMixpanelEvent($mixpanelEvents);
                $progressBar->advance($eventCount);
                $eventCount = 0;
                $mixpanelEvents = [];
            }
        }

        if (!empty($mixpanelEvents)) {
            $this->postMixpanelEvent($mixpanelEvents);
        }

        $progressBar->finish();
    }

    private function markEventAsProcessed($id, $create = true)
    {
        $path = $this->importedDocumentsDirectory;

        for ($i = 0; $i <= 4; $i++) {
            $directory = substr($id, $i, 1);
            $path .= DIRECTORY_SEPARATOR . $directory;

            if ($create && !is_dir($path)) {
                $this->filesystem->mkdir($path);
            }
        }

        $path .= DIRECTORY_SEPARATOR . $id;

        if ($create) {
            $this->filesystem->touch($path);
            return true;
        }

        return $this->filesystem->exists($path);
    }

    private function postMixpanelEvent(array $events): void
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->mixpanelEndpoint,
                [
                    RequestOptions::BODY => json_encode($events),
                    RequestOptions::HEADERS => [
                        'accept' => 'application/json',
                        'content-type' => 'application/json',
                    ],
                    RequestOptions::AUTH => [ $_ENV['SA_USERNAME'], $_ENV['SA_PASSWORD'] ],
                ]
            );
        } catch (RequestException $exception) {
            dump($events);
            dump($exception);
            dump($exception->getResponse()->getBody()->getContents());
            foreach ($events as $event) {
                dump($event['properties']['$insert_id']);
            }
            die;
        }

        $jsonResponse = json_decode($response->getBody()->getContents(), true);
        $this->logger->log(LogLevel::INFO, sprintf('%d Events importiert.', $jsonResponse['num_records_imported']));
    }
}