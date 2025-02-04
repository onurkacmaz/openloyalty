<?php
/**
 * Copyright © 2017 Divante, Inc. All rights reserved.
 * See LICENSE for license details.
 */
namespace OpenLoyalty\Component\Account\Infrastructure\Repository;

use Elasticsearch\Common\Exceptions\Missing404Exception;
use OpenLoyalty\Component\Account\Domain\ReadModel\PointsTransferDetails;
use OpenLoyalty\Component\Account\Domain\ReadModel\PointsTransferDetailsRepository;
use OpenLoyalty\Component\Core\Infrastructure\Repository\OloyElasticsearchRepository;

/**
 * Class PointsTransferDetailsRepository.
 */
class PointsTransferDetailsElasticsearchRepository extends OloyElasticsearchRepository implements PointsTransferDetailsRepository
{
    /**
     * {@inheritdoc}
     */
    public function findAllActiveAddingTransfersExpiredAfter(int $timestamp): array
    {
        $filter = [];
        $filter[] = [
            'term' => [
                'state' => PointsTransferDetails::STATE_ACTIVE,
            ],
        ];
        $filter[] = [
            'term' => [
                'type' => PointsTransferDetails::TYPE_ADDING,
            ],
        ];

        $filter[] = [
            'range' => [
                'expiresAt' => [
                    'lt' => $timestamp,
                ],
            ],
        ];

        $query = [
            'bool' => [
                'must' => $filter,
            ],
        ];

        return $this->query($query);
    }

    public function findAllActiveAddingTransfersCreatedAfter($timestamp)
    {
        $filter = [];
        $filter[] = ['term' => [
            'state' => PointsTransferDetails::STATE_ACTIVE,
        ]];
        $filter[] = ['term' => [
            'type' => PointsTransferDetails::TYPE_ADDING,
        ]];

        $filter[] = ['range' => [
            'createdAt' => [
                'lt' => $timestamp,
            ],
        ]];

        $query = array(
            'bool' => array(
                'must' => $filter,
            ),
        );

        return $this->query($query);
    }

    public function findAllPaginated($page = 1, $perPage = 10, $sortField = 'pointsTransferId', $direction = 'DESC')
    {
        $query = array(
            'filtered' => array(
                'query' => array(
                    'match_all' => array(),
                ),
            ),
        );

        return $this->query($query);
    }

    public function countTotalSpendingTransfers()
    {
        return $this->countTotal(['type' => 'spending']);
    }

    public function getTotalValueOfSpendingTransfers()
    {
        $query = array(
            'index' => $this->index,
            'body' => array(
                'query' => [
                    'bool' => [
                        'must' => [
                            'term' => ['type' => PointsTransferDetails::TYPE_SPENDING],
                        ],
                        'filter' => [
                            'not' => [
                                'term' => ['state' => PointsTransferDetails::STATE_CANCELED],
                            ],
                        ],
                    ],
                ],
                'aggregations' => [
                    'summary' => [
                        'sum' => ['field' => 'value'],
                    ],
                ],
            ),
            'size' => 0,
        );

        try {
            $result = $this->client->search($query);
        } catch (Missing404Exception $e) {
            return 0;
        }

        if (!array_key_exists('aggregations', $result)) {
            return 0;
        }

        if (!array_key_exists('summary', $result['aggregations'])) {
            return 0;
        }

        if (!array_key_exists('value', $result['aggregations']['summary'])) {
            return 0;
        }

        return $result['aggregations']['summary']['value'];
    }
}
