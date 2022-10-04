<?php

namespace ConversionMaker\MixpanelImportExport\Command;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
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
    name: 'cm:users:import',
    description: 'Execute the migration script.',
)]

class ImportUsersCommand extends Command
{
    private ConsoleLogger $logger;

    private Client $httpClient;

    private string $mixpanelEndpoint;

    private string $importDirectory;

    private string $importFile;

    private OutputInterface $output;

    protected function configure()
    {
        $this->addArgument('importDirectory', InputArgument::REQUIRED, 'Path to the JSON import file.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->logger = new ConsoleLogger($output);
        $this->httpClient = new Client();

        $this->mixpanelEndpoint = 'https://api-eu.mixpanel.com/engage?project_id=' . $_ENV['PROJECT_ID'] . '&verbose=1#profile-set';
        $this->importDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $input->getArgument('importDirectory');
        $filesystem = new Filesystem();
        $importedDocumentsDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exported';

        if (!is_dir($importedDocumentsDirectory)) {
            $filesystem->mkdir($importedDocumentsDirectory);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $finder = new Finder();
        $finder->files()->in($this->importDirectory)->sortByName();
        $this->output = $output;

        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                $this->importFile = $file->getRealPath();
                $this->logger->log(LogLevel::NOTICE, sprintf('Start to import events from "%s"', $this->importFile));
                $this->processUserData($this->loadFile());
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

    private function processUserData(array $dataToImport): void
    {
        $dataToImport = $dataToImport[0];
        $progressBar = new ProgressBar($this->output, count($dataToImport));
        $progressBar->start();

        foreach ($dataToImport as $data) {
            $properties = $data['$properties'];
            $body = json_encode([ 0 => ['$token' => '8d8582796dbeaf0dc3a32378e1bcb855', '$distinct_id' => $data['$distinct_id'], '$set' => $properties]]);
            $request = $this->httpClient->request('POST', $this->mixpanelEndpoint,
                [
                    RequestOptions::BODY => $body,
                    RequestOptions::HEADERS => [
                        'accept' => 'application/json',
                        'content-type' => 'application/json',
                    ],
                    RequestOptions::AUTH => [ $_ENV['SA_USERNAME'], $_ENV['SA_PASSWORD'] ],
                ]);
            $response = json_decode($request->getBody()->getContents(), true);

            if ($response['error']) {
                dump($response);
                die;
            }

            $progressBar->advance(1);
        }



        $progressBar->finish();
    }
}