<?php

namespace OpenLoyalty\Component\Campaign\Tests\Integration\Domain\Command;

use OpenLoyalty\Component\Campaign\Domain\Campaign;
use OpenLoyalty\Component\Campaign\Domain\CampaignId;
use OpenLoyalty\Component\Campaign\Domain\Command\CreateCampaign;
use OpenLoyalty\Component\Campaign\Domain\LevelId;
use OpenLoyalty\Component\Campaign\Domain\Model\Coupon;
use OpenLoyalty\Component\Campaign\Tests\Domain\Command\CampaignCommandHandlerTest;

/**
 * Class CreateCampaignTest.
 */
class CreateCampaignTest extends CampaignCommandHandlerTest
{
    /**
     * @test
     */
    public function it_creates_new_campaign()
    {
        $handler = $this->createCommandHandler();
        $campaignId = new CampaignId('00000000-0000-0000-0000-000000000000');

        $command = new CreateCampaign($campaignId, [
            'name' => 'test',
            'reward' => Campaign::REWARD_TYPE_GIFT_CODE,
            'levels' => [new LevelId('00000000-0000-0000-0000-000000000000')],
            'segments' => [],
            'unlimited' => false,
            'limit' => 10,
            'limitPerUser' => 2,
            'singleCoupon' => false,
            'coupons' => [new Coupon('123')],
            'campaignActivity' => [
                'allTimeActive' => false,
                'activeFrom' => new \DateTime('2016-01-01'),
                'activeTo' => new \DateTime('2016-01-11'),
            ],
            'campaignVisibility' => [
                'allTimeVisible' => false,
                'visibleFrom' => new \DateTime('2016-02-01'),
                'visibleTo' => new \DateTime('2016-02-11'),
            ],
            'taxPriceValue' => 99.95,
            'tax' => 23,
        ]);
        $handler->handle($command);
        $campaign = $this->inMemoryRepository->byId($campaignId);
        $this->assertNotNull($campaign);
        $this->assertInstanceOf(Campaign::class, $campaign);
        $this->assertEquals(23, $campaign->getTax());
    }

    /**
     * @test
     * @expectedException \Assert\InvalidArgumentException
     */
    public function it_validates_required_fields()
    {
        $handler = $this->createCommandHandler();
        $campaignId = new CampaignId('00000000-0000-0000-0000-000000000000');

        $command = new CreateCampaign($campaignId, [
            'reward' => Campaign::REWARD_TYPE_GIFT_CODE,
            'unlimited' => false,
            'limit' => 10,
            'limitPerUser' => 2,
            'coupons' => [new Coupon('123')],
            'campaignActivity' => [
                'allTimeActive' => false,
                'activeFrom' => new \DateTime('2016-01-01'),
                'activeTo' => new \DateTime('2016-01-11'),
            ],
            'campaignVisibility' => [
                'allTimeVisible' => false,
                'visibleFrom' => new \DateTime('2016-02-01'),
                'visibleTo' => new \DateTime('2016-02-11'),
            ],
        ]);
        $handler->handle($command);
        $campaign = $this->inMemoryRepository->byId($campaignId);
        $this->assertNotNull($campaign);
        $this->assertInstanceOf(Campaign::class, $campaign);
    }
}
