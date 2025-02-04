<?php
/**
 * Copyright © 2017 Divante, Inc. All rights reserved.
 * See LICENSE for license details.
 */
namespace OpenLoyalty\Bundle\PointsBundle\DataFixtures\ORM;

use Broadway\ReadModel\Repository;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use OpenLoyalty\Bundle\PointsBundle\Service\PointsTransfersManager;
use OpenLoyalty\Bundle\UserBundle\DataFixtures\ORM\LoadUserData;
use OpenLoyalty\Component\Account\Domain\AccountId;
use OpenLoyalty\Component\Account\Domain\Command\AddPoints;
use OpenLoyalty\Component\Account\Domain\Command\ExpirePointsTransfer;
use OpenLoyalty\Component\Account\Domain\Command\SpendPoints;
use OpenLoyalty\Component\Account\Domain\Model\SpendPointsTransfer;
use OpenLoyalty\Component\Account\Domain\PointsTransferId;
use OpenLoyalty\Component\Account\Domain\ReadModel\AccountDetails;
use Symfony\Bridge\Doctrine\Tests\Fixtures\ContainerAwareFixture;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class LoadAccountsWithTransfersData.
 */
class LoadAccountsWithTransfersData extends ContainerAwareFixture implements OrderedFixtureInterface
{
    const ACCOUNT2_ID = 'e82c96cf-32a3-43bd-9034-4df343e5fd92';
    const POINTS_ID = 'e82c96cf-32a3-43bd-9034-4df343e5f111';
    const POINTS22_ID = 'e82c96cf-32a3-43bd-9034-4df343e5f211';
    const POINTS2_ID = 'e82c96cf-32a3-43bd-9034-4df343e5f222';
    const POINTS3_ID = 'e82c96cf-32a3-43bd-9034-4df343e5f333';
    const POINTS4_ID = 'e82c96cf-32a3-43bd-9034-4df343e5f433';

    public function load(ObjectManager $manager)
    {
        /*
         * @var PointsTransfersManager
         */
        $pointsTransferManager = $this->getContainer()->get(PointsTransfersManager::class);
        $commandBud = $this->container->get('broadway.command_handling.command_bus');
        $accountId = $this->getAccountIdByCustomerId(LoadUserData::TEST_USER_ID);
        $account2Id = $this->getAccountIdByCustomerId(LoadUserData::USER_USER_ID);

        $commandBud->dispatch(
            new AddPoints(new AccountId($accountId), $pointsTransferManager->createAddPointsTransferInstance(new PointsTransferId(static::POINTS_ID), 100, new \DateTime('-29 days')))
        );

        $commandBud->dispatch(
            new AddPoints(new AccountId($account2Id), $pointsTransferManager->createAddPointsTransferInstance(new PointsTransferId(static::POINTS22_ID), 100, new \DateTime('-29 days')))
        );
        $commandBud->dispatch(
            new AddPoints(new AccountId($accountId), $pointsTransferManager->createAddPointsTransferInstance(new PointsTransferId(static::POINTS4_ID), 100, new \DateTime('-29 days')))
        );
        $commandBud->dispatch(
            new AddPoints(new AccountId($accountId), $pointsTransferManager->createAddPointsTransferInstance(new PointsTransferId(static::POINTS2_ID), 100, new \DateTime('-3 days')))
        );
        $commandBud->dispatch(
            new SpendPoints(new AccountId($accountId), new SpendPointsTransfer(new PointsTransferId(static::POINTS3_ID), 100, null, false, 'Example comment'))
        );
        $commandBud->dispatch(
            new ExpirePointsTransfer(new AccountId($accountId), new PointsTransferId(static::POINTS_ID))
        );
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Get the order of this fixture.
     *
     * @return int
     */
    public function getOrder()
    {
        return 10;
    }

    /**
     * @param int $customerId
     *
     * @return string
     */
    protected function getAccountIdByCustomerId($customerId)
    {
        /** @var Repository $repo */
        $repo = $this->getContainer()->get('oloy.points.account.repository.account_details');
        $accounts = $repo->findBy(['customerId' => $customerId]);
        /** @var AccountDetails $account */
        $account = reset($accounts);
        $accountId = $account->getAccountId()->__toString();

        return $accountId;
    }
}
