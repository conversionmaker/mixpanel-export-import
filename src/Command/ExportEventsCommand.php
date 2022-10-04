<?php

namespace ConversionMaker\MixpanelImportExport\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'cm:events:export',
    description: 'Execute the migration script.',
)]

class ExportEventsCommand extends Command
{
    private ConsoleLogger $logger;

    private Client $httpClient;

    private string $mixpanelEndpoint;

    private Filesystem $filesystem;

    private string $exportedDocumentsDirectory;

    private OutputInterface $output;

    private array $years = [ '2019' ];
    private array $month = [ '08', '09', '10', '11', '12' ];

    protected function configure()
    {
        $this->addArgument('importDirectory', InputArgument::REQUIRED, 'Path to the JSON import file.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->logger = new ConsoleLogger($output);
        $this->httpClient = new Client();

        $this->mixpanelEndpoint = $_ENV['BASE_URL'];
        $this->filesystem = new Filesystem();
        $this->exportedDocumentsDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exported';

        if (!is_dir($this->exportedDocumentsDirectory)) {
            $this->filesystem->mkdir($this->exportedDocumentsDirectory);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->exportEvents();

        return Command::SUCCESS;
    }

    protected function exportEvents()
    {
        foreach ($this->years as $year) {
            foreach ($this->month as $month) {
                $fromDate = sprintf('%s-%s-01', $year, $month);

                foreach ([10, 20, 30] as $key => $day) {
                    $endDate = $day === 30 ? date('Y-m-t', strtotime($fromDate)) : sprintf('%s-%s-%s', $year, $month, (string)$day);

                    $this->logger->debug(sprintf('%s - %s', $fromDate, $endDate));
                    $url = sprintf('%s?from_date=%s&to_date=%s&project_id=%s', $this->mixpanelEndpoint, $fromDate, $endDate, $_ENV['PROJECT_ID']);

                    try {
                        $request = $this->httpClient->request('GET', $url, [
                            RequestOptions::HEADERS => [
                                'accept' => 'application/json',
                            ],
                            RequestOptions::AUTH => [ $_ENV['SA_USERNAME'], $_ENV['SA_PASSWORD'] ],
                        ]);
                        $this->filesystem->dumpFile(sprintf('%s/events-%s-%s-%s.json', $this->exportedDocumentsDirectory, $year, $month, (string)$key), $request->getBody()->getContents());
                    } catch (ClientException $exception) {
                        dump($exception->getRequest());
                        dump($exception->getMessage());
                    }

                    $day++;
                    $fromDate = sprintf('%s-%s-%s', $year, $month, (string)$day);
                }
            }
        }
    }
}