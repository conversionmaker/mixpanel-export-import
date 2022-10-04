<?php

namespace ConversionMaker\MixpanelImportExport\Command;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'cm:users:export',
    description: 'Execute the migration script.',
)]

class ExportUsersCommand extends Command
{
    private ConsoleLogger $logger;

    private Client $httpClient;

    private string $mixpanelEndpoint;

    private Filesystem $filesystem;

    private string $exportedDocumentsDirectory;

    protected function configure()
    {
        $this->addArgument('importDirectory', InputArgument::REQUIRED, 'Path to the JSON import file.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->logger = new ConsoleLogger($output);
        $this->httpClient = new Client();

        $this->mixpanelEndpoint = 'https://eu.mixpanel.com/api/2.0/engage?project_id=' . $_ENV['PROJECT_ID'];
        $this->filesystem = new Filesystem();
        $this->exportedDocumentsDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exported';

        if (!is_dir($this->exportedDocumentsDirectory)) {
            $this->filesystem->mkdir($this->exportedDocumentsDirectory);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->exportUsers();

        return Command::SUCCESS;
    }

    protected function exportUsers()
    {
        $results = 1;
        $count = 0;
        $requestOptions = [
            RequestOptions::HEADERS => [
                'accept' => 'application/json',
            ],
            RequestOptions::AUTH => [ $_ENV['SA_USERNAME'], $_ENV['SA_PASSWORD'] ],
            RequestOptions::FORM_PARAMS => [ 'page' => 0, 'filter_by_cohort' => json_encode([ 'id' => '2351196' ]) ]
        ];

        while ($results > 0) {
            $this->logger->notice('Start to process page ' . $count);
            $request = $this->httpClient->request('POST', $this->mixpanelEndpoint, $requestOptions);

            $response = json_decode($request->getBody()->getContents(), true);
            $this->filesystem->dumpFile($this->exportedDocumentsDirectory . '/users-' . $count . '.json', json_encode($response['results']));
            $count++;
            $requestOptions[RequestOptions::FORM_PARAMS] = [ 'session_id' => $response['session_id'], 'page' => $count,  'filter_by_cohort' => json_encode([ 'id' => '2351196' ]) ];
            $results = $response['total'] ?? count($response['results']);
        }
    }
}