<?php
/**
 * Copyright © 2017 Divante, Inc. All rights reserved.
 * See LICENSE for license details.
 */
namespace OpenLoyalty\Bundle\UserBundle\Controller\Api;

use Broadway\ReadModel\Repository;
use FOS\RestBundle\Context\Context;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\Annotations\Route;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcher;
use FOS\RestBundle\View\View;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use OpenLoyalty\Bundle\AuditBundle\Service\AuditManagerInterface;
use OpenLoyalty\Bundle\ImportBundle\Form\Type\ImportFileFormType;
use OpenLoyalty\Bundle\ImportBundle\Service\ImportFileManager;
use OpenLoyalty\Bundle\UserBundle\Entity\Customer;
use OpenLoyalty\Bundle\UserBundle\Entity\Seller;
use OpenLoyalty\Bundle\UserBundle\Entity\Status;
use OpenLoyalty\Bundle\UserBundle\Entity\User;
use OpenLoyalty\Bundle\UserBundle\Event\UserRegisteredWithInvitationToken;
use OpenLoyalty\Bundle\UserBundle\Form\Type\CustomerEditFormType;
use OpenLoyalty\Bundle\UserBundle\Form\Type\CustomerRegistrationFormType;
use OpenLoyalty\Bundle\UserBundle\Form\Type\CustomerSelfRegistrationFormType;
use OpenLoyalty\Bundle\UserBundle\Import\CustomerXmlImporter;
use OpenLoyalty\Bundle\UserBundle\Service\RegisterCustomerManager;
use OpenLoyalty\Component\Customer\Domain\Command\ActivateCustomer;
use OpenLoyalty\Component\Customer\Domain\Command\AssignPosToCustomer;
use OpenLoyalty\Component\Customer\Domain\Command\AssignSellerToCustomer;
use OpenLoyalty\Component\Customer\Domain\Command\DeactivateCustomer;
use OpenLoyalty\Component\Customer\Domain\Command\MoveCustomerToLevel;
use OpenLoyalty\Component\Customer\Domain\Command\RemoveManuallyAssignedLevel;
use OpenLoyalty\Component\Customer\Domain\CustomerId;
use OpenLoyalty\Component\Customer\Domain\Model\AccountActivationMethod;
use OpenLoyalty\Component\Customer\Domain\PosId;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetails;
use OpenLoyalty\Component\Customer\Domain\LevelId;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetailsRepository;
use OpenLoyalty\Component\Segment\Domain\ReadModel\SegmentedCustomers;
use OpenLoyalty\Component\Segment\Domain\ReadModel\SegmentedCustomersRepository;
use OpenLoyalty\Component\Seller\Domain\ReadModel\SellerDetails;
use OpenLoyalty\Component\Seller\Domain\SellerId;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use OpenLoyalty\Component\Customer\Domain\SellerId as CustomerSellerId;

/**
 * Class CustomerController.
 */
class CustomerController extends FOSRestController
{
    /**
     * Method will return list of all customers.
     *
     * @Route(name="oloy.customer.list", path="/customer")
     * @Route(name="oloy.customer.admin_list", path="/admin/customer")
     * @Method("GET")
     * @Security("is_granted('LIST_CUSTOMERS')")
     *
     * @ApiDoc(
     *     name="Customers list",
     *     section="Customer",
     *     parameters={
     *      {"name"="strict", "dataType"="boolean", "required"=false, "description"="Strict filtering"},
     *      {"name"="page", "dataType"="integer", "required"=false, "description"="Page number"},
     *      {"name"="perPage", "dataType"="integer", "required"=false, "description"="Number of elements per page"},
     *      {"name"="sort", "dataType"="string", "required"=false, "description"="Field to sort by"},
     *      {"name"="direction", "dataType"="asc|desc", "required"=false, "description"="Sorting direction"},
     *     }
     * )
     *
     * @param Request      $request
     * @param ParamFetcher $paramFetcher
     *
     * @return View
     *
     * @QueryParam(name="firstName", nullable=true, description="firstName"))
     * @QueryParam(name="lastName", nullable=true, description="lastName"))
     * @QueryParam(name="phone", nullable=true, description="phone"))
     * @QueryParam(name="email", nullable=true, description="email"))
     * @QueryParam(name="emailOrPhone", nullable=true, description="email or phone"))
     * @QueryParam(name="loyaltyCardNumber", nullable=true, description="loyaltyCardNumber"))
     * @QueryParam(name="transactionsAmount", nullable=true, description="transactionsAmount"))
     * @QueryParam(name="averageTransactionAmount", nullable=true, description="averageTransactionAmount"))
     * @QueryParam(name="transactionsCount", nullable=true, description="transactionsCount"))
     * @QueryParam(name="daysFromLastTransaction", nullable=true, description="daysFromLastTransaction"))
     * @QueryParam(name="hoursFromLastUpdate", nullable=true, description="hoursFromLastUpdate"))
     * @QueryParam(name="manuallyAssignedLevel", nullable=true, description="manuallyAssignedLevel"))
     */
    public function listAction(Request $request, ParamFetcher $paramFetcher)
    {
        $types = [
            'transactionsAmount' => 'number',
            'averageTransactionAmount' => 'number',
            'transactionsCount' => 'number',
        ];

        $params = $this->get('oloy.user.param_manager')->stripNulls($paramFetcher->all(), true, true, $types);

        if (isset($params['daysFromLastTransaction'])) {
            $days = $params['daysFromLastTransaction'];
            unset($params['daysFromLastTransaction']);
            $params['lastTransactionDate'] = [
                'type' => 'range',
                'value' => [
                    'gte' => (new \DateTime('-'.$days.' days'))->getTimestamp(),
                ],
            ];
        }
        if (isset($params['hoursFromLastUpdate'])) {
            $hoursFromLastUpdate = $params['hoursFromLastUpdate'];
            unset($params['hoursFromLastUpdate']);
            $params['updatedAt'] = [
                'type' => 'range',
                'value' => [
                    'gte' => (new \DateTime('-'.$hoursFromLastUpdate.' hours'))->getTimestamp(),
                ],
            ];
        }
        if (isset($params['emailOrPhone'])) {
            $emailOrPhone = $params['emailOrPhone'];
            $params['emailOrPhone'] = [
                'type' => 'multiple',
                'fields' => [
                    'email' => $emailOrPhone,
                    'phone' => $emailOrPhone,
                ],
            ];
        }
        if (isset($params['manuallyAssignedLevel'])) {
            if ($params['manuallyAssignedLevel']) {
                $params['manuallyAssignedLevelId'] = [
                    'type' => 'exists',
                ];
            }
            unset($params['manuallyAssignedLevel']);
        }
        $pagination = $this->get('oloy.pagination')->handleFromRequest($request, 'createdAt', 'desc');

        /** @var CustomerDetailsRepository $repo */
        $repo = $this->get('oloy.user.read_model.repository.customer_details');
        $customers = $repo->findByParametersPaginated(
            $params,
            $request->get('strict', false),
            $pagination->getPage(),
            $pagination->getPerPage(),
            $pagination->getSort(),
            $pagination->getSortDirection()
        );
        $total = $repo->countTotal($params, $request->get('strict', false));

        return $this->view(
            [
                'customers' => $customers,
                'total' => $total,
            ],
            200
        );
    }

    /**
     * Method will return customer details.
     *
     * @Route(name="oloy.customer.get", path="/customer/{customer}")
     * @Method("GET")
     * @Security("is_granted('VIEW', customer)")
     *
     * @ApiDoc(
     *     name="Get Customer",
     *     section="Customer"
     * )
     *
     * @param CustomerDetails $customer
     *
     * @return View
     */
    public function getCustomerAction(CustomerDetails $customer)
    {
        $view = $this->view(
            $customer,
            200
        );
        /** @var SegmentedCustomersRepository $repo */
        $repo = $this->get('oloy.segment.read_model.repository.segmented_customers');
        $segments = $repo->findBy(['customerId' => $customer->getCustomerId()->__toString()]);
        $serializer = $this->get('serializer');
        $segments = array_map(
            function (SegmentedCustomers $segment) use ($serializer) {
                return $serializer->toArray($segment);
            },
            $segments
        );

        $auditManager = $this->container->get('oloy.audit.manager');
        $auditManager->auditCustomerEvent(AuditManagerInterface::VIEW_CUSTOMER_EVENT_TYPE, $customer->getCustomerId());

        $context = new Context();
        $context->addGroup('Default');
        $context->setAttribute('customerSegments', $segments);

        $view->setContext($context);

        return $view;
    }

    /**
     * Method will return number of customer registrations per day in last 30 days.
     *
     * @Route(name="oloy.customer.get_customers_registrations_in_time", path="/customer/registrations/daily")
     * @Method("GET")
     *
     * @ApiDoc(
     *     name="Get Customers registrations in time",
     *     section="Customer"
     * )
     *
     * @return View
     */
    public function getCustomersRegistrationsDailyAction()
    {
        /** @var CustomerDetailsRepository $repo */
        $repo = $this->get('oloy.user.read_model.repository.customer_details');
        $date = new \DateTime();
        $date->setTime(0, 0, 0);
        $date->modify('-30 days');
        $customers = $repo->findByParameters(
            [
                'createdAt' => [
                    'type' => 'range',
                    'value' => [
                        'gte' => $date->getTimestamp(),
                    ],
                ],
            ]
        );

        $result = [];
        $now = new \DateTime();
        $now->setTime(0, 0, 0);

        while ($date < $now) {
            $result[$date->format('Y-m-d')] = 0;
            $date->modify('+1 day');
        }

        /** @var CustomerDetails $customer */
        foreach ($customers as $customer) {
            $tmp = $customer->getCreatedAt()->format('Y-m-d');
            if (!isset($result[$tmp])) {
                continue;
            }
            ++$result[$tmp];
        }

        return $this->view($result);
    }

    /**
     * Method will return customer status<br/>
     * [Example response]<br/>
     * <pre>.
     *
     * {
     * "firstName": "Jane",
     * "lastName": "Doe",
     * "customerId": "00000000-0000-474c-b092-b0dd880c07e2",
     * "points": 206,
     * "usedPoints": 100,
     * "expiredPoints": 0,
     * "level": "14.00%",
     * "levelName": "level0",
     * "nextLevel": "15.00%",
     * "nextLevelName": "level1",
     * "transactionsAmountWithoutDeliveryCosts": 3,
     * "transactionsAmountToNextLevel": 17,
     * "averageTransactionsAmount": "3.00",
     * "transactionsCount": 1,
     * "transactionsAmount": 3,
     * "currency": "eur",
     * }
     * </pre>
     *
     * @Route(name="oloy.customer.get_status", path="/customer/{customer}/status")
     * @Route(name="oloy.customer.admin_get_status", path="/admin/customer/{customer}/status")
     * @Route(name="oloy.customer.seller_get_status", path="/seller/customer/{customer}/status")
     * @Method("GET")
     * @Security("is_granted('VIEW_STATUS', customer)")
     *
     * @ApiDoc(
     *     name="Get Customer status",
     *     section="Customer"
     * )
     *
     * @param CustomerDetails $customer
     *
     * @return View
     */
    public function getCustomerStatusAction(CustomerDetails $customer)
    {
        return $this->view(
            $this->get('oloy.customer_status_provider')->getStatus($customer->getCustomerId()),
            200
        );
    }

    /**
     * Method allows to register new customer.
     *
     * @param Request                 $request
     * @param RegisterCustomerManager $registerCustomerManager
     *
     * @return View
     * @Route(name="oloy.customer.admin_register_customer", path="/admin/customer/register")
     * @Route(name="oloy.customer.register_customer", path="/customer/register")
     * @Route(name="oloy.customer.seller.register_customer", path="/seller/customer/register")
     * @Security("is_granted('CREATE_CUSTOMER')")
     *
     * @Method("POST")
     * @ApiDoc(
     *     name="Register Customer",
     *     section="Customer",
     *     input={"class" = "OpenLoyalty\Bundle\UserBundle\Form\Type\CustomerRegistrationFormType", "name" =
     *     "customer"}, statusCodes={
     *       200="Returned when successful",
     *       400="Returned when form contains errors",
     *     }
     * )
     */
    public function registerCustomerAction(Request $request, RegisterCustomerManager $registerCustomerManager)
    {
        $loggedUser = $this->getUser();
        $accountActivationMethod = $this->get('oloy.action_token_manager')->getCurrentMethod();

        $formOptions = [];
        $formOptions['includeLevelId'] = true;
        $formOptions['includePosId'] = true;
        $formOptions['activationMethod'] = $accountActivationMethod;
        if (!$this->isGranted('ROLE_SELLER')) {
            $formOptions['includeSellerId'] = true;
        }

        $form = $this->get('form.factory')->createNamed(
            'customer',
            CustomerRegistrationFormType::class,
            null,
            $formOptions
        );

        $form->handleRequest($request);

        if ($form->isValid()) {
            $customerId = new CustomerId($this->get('broadway.uuid.generator')->generate());

            $user = $this->get('oloy.user.form_handler.customer_registration')->onSuccess($customerId, $form);

            if ($user instanceof User) {
                $user->setStatus(Status::typeNew());
                $levelId = $form->get('levelId')->getData();
                $posId = $form->get('posId')->getData();
                $sellerId = $form->has('sellerId') ? $form->get('sellerId')->getData() : null;
                $agreement2 = $form->get('agreement2')->getData();
                $commandBus = $this->get('broadway.command_handling.command_bus');

                if (!$posId && $this->isGranted('ROLE_SELLER')) {
                    $this->handleSellerWasACreator($loggedUser, $customerId, $user);
                } elseif ($posId) {
                    $commandBus->dispatch(
                        new AssignPosToCustomer($customerId, new PosId($posId))
                    );
                }
                if ($this->isGranted('ROLE_SELLER')) {
                    $sellerId = (string) $loggedUser->getId();
                }
                if ($levelId) {
                    $commandBus->dispatch(
                        new MoveCustomerToLevel($customerId, new LevelId($levelId), true)
                    );
                }
                if ($sellerId) {
                    $commandBus->dispatch(
                        new AssignSellerToCustomer($customerId, new CustomerSellerId($sellerId))
                    );
                }

                if ($this->isGranted('ROLE_ADMIN')
                    || (
                        $this->isGranted('ROLE_SELLER')
                        && !AccountActivationMethod::isMethodSms($accountActivationMethod)
                    )
                ) {
                    $commandBus->dispatch(
                        new ActivateCustomer($customerId)
                    );
                    $registerCustomerManager->activate($user);
                } else {
                    $this->get('oloy.action_token_manager')
                        ->sendActivationMessage($user);
                }

                if ($agreement2) {
                    $registerCustomerManager->dispatchNewsletterSubscriptionEvent($user, $customerId);
                }

                return $this->view(
                    [
                        'customerId' => $customerId->__toString(),
                        'email' => $user->getEmail(),
                    ]
                );
            }

            return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
        }

        return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Method allow to register by myself.
     *
     * @param Request $request
     * @Route(name="oloy.customer.self_register_customer", path="/customer/self_register")
     *
     * @Method("POST")
     * @ApiDoc(
     *     name="Register Customer",
     *     section="Customer",
     *     input={"class" = "OpenLoyalty\Bundle\UserBundle\Form\Type\CustomerSelfRegistrationFormType", "name" =
     *     "customer"}, statusCodes={
     *       200="Returned when successful",
     *       400="Returned when form contains errors",
     *     }
     * )
     *
     * @return View
     */
    public function selfRegisterAction(Request $request)
    {
        $accountActivationMethod = $this->get('oloy.action_token_manager')->getCurrentMethod();
        $form = $this->get('form.factory')->createNamed(
            'customer',
            CustomerSelfRegistrationFormType::class,
            null,
            [
                'activationMethod' => $accountActivationMethod,
            ]
        );

        $form->handleRequest($request);

        if ($form->isValid()) {
            $customerId = new CustomerId($this->get('broadway.uuid.generator')->generate());

            $user = $this->get('oloy.user.form_handler.customer_registration')->onSuccess($customerId, $form);

            if ($user instanceof User) {
                $referralCustomerEmail = $form->get('referral_customer_email')->getData();

                $this->get('oloy.user.form_handler.customer_registration')
                    ->handleCustomerRegisteredByHimself($user, $referralCustomerEmail);

                if ($invitationToken = $form->get('invitationToken')->getData()) {
                    $this->get('event_dispatcher')->dispatch(
                        UserRegisteredWithInvitationToken::NAME,
                        new UserRegisteredWithInvitationToken($invitationToken, $customerId)
                    );
                }

                return $this->view(
                    [
                        'customerId' => $customerId->__toString(),
                        'email' => $user->getEmail(),
                    ]
                );
            }

            return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
        }

        return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Method allows to update customer details.
     *
     * @param Request                 $request
     * @param CustomerDetails         $customer
     * @param RegisterCustomerManager $registerCustomerManager
     *
     * @return View
     * @Route(name="oloy.customer.edit_customer", path="/customer/{customer}")
     * @Security("is_granted('EDIT', customer)")
     *
     * @Method("PUT")
     * @ApiDoc(
     *     name="Edit Customer",
     *     section="Customer",
     *     input={"class" = "OpenLoyalty\Bundle\UserBundle\Form\Type\CustomerEditFormType", "name" = "customer"},
     *     statusCodes={
     *       200="Returned when successful",
     *       400="Returned when form contains errors",
     *     }
     * )
     */
    public function editCustomerAction(Request $request, CustomerDetails $customer, RegisterCustomerManager $registerCustomerManager)
    {
        $loggedUser = $this->getUser();
        $accountActivationMethod = $this->get('oloy.action_token_manager')->getCurrentMethod();

        $options = [
            'method' => 'PUT',
            'includeLevelId' => true,
            'includePosId' => true,
            'activationMethod' => $accountActivationMethod,
        ];

        if (!$this->isGranted('ROLE_SELLER') && !$loggedUser instanceof Seller) {
            $options['includeSellerId'] = true;
        }

        $form = $this->get('form.factory')->createNamed(
            'customer',
            CustomerEditFormType::class,
            [],
            $options
        );

        $form->handleRequest($request);

        if ($form->isValid()) {
            $ret = $this->get('oloy.user.form_handler.customer_edit')->onSuccess($customer->getCustomerId(), $form);

            if ($ret !== true) {
                return $this->view($ret, Response::HTTP_BAD_REQUEST);
            }

            /** @var CustomerDetailsRepository $repo */
            $repo = $this->get('oloy.user.read_model.repository.customer_details');
            /** @var CustomerDetails $customer */
            $customer = $repo->find($customer->getCustomerId()->__toString());

            $levelId = $form->get('levelId')->getData();
            $posId = $form->get('posId')->getData();
            $sellerId = $form->has('sellerId') ? $form->get('sellerId')->getData() : null;
            $commandBus = $this->get('broadway.command_handling.command_bus');
            if ($posId) {
                $commandBus->dispatch(
                    new AssignPosToCustomer($customer->getCustomerId(), new PosId($posId))
                );
            }
            if ($levelId) {
                if ($customer->getLevelId() != $levelId) {
                    $commandBus->dispatch(
                        new MoveCustomerToLevel($customer->getCustomerId(), new LevelId($levelId), true)
                    );
                }
            }
            if ($sellerId) {
                $commandBus->dispatch(
                    new AssignSellerToCustomer($customer->getCustomerId(), new \OpenLoyalty\Component\Customer\Domain\SellerId($sellerId))
                );
            }

            if ($customer->isAgreement2()) {
                $em = $this->getDoctrine()->getManager();
                $user = $em->getRepository('OpenLoyaltyUserBundle:Customer')->findOneBy(['id' => $customer->getId()]);
                $registerCustomerManager->dispatchNewsletterSubscriptionEvent($user, $customer->getCustomerId());
            }

            return $this->view($customer);
        }

        return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Method allows to assign level to customer.
     *
     * @param Request         $request
     * @param CustomerDetails $customer
     *
     * @return View
     * @Route(name="oloy.customer.add_customer_to_level", path="/customer/{customer}/level")
     * @Method("POST")
     * @Security("is_granted('ASSIGN_CUSTOMER_LEVEL', customer)")
     *
     * @ApiDoc(
     *     name="Add customer to level",
     *     section="Customer",
     *     parameters={{"name"="levelId", "dataType"="string", "required"=true}},
     *     statusCodes={
     *       200="Returned when successful",
     *       400="Returned when levelId is not provided or customer does not exist",
     *     }
     * )
     */
    public function addCustomerToLevelAction(Request $request, CustomerDetails $customer)
    {
        $levelId = $request->request->get('levelId');
        if (!$levelId) {
            return $this->view(['levelId' => 'field is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->get('broadway.command_handling.command_bus')->dispatch(
            new MoveCustomerToLevel($customer->getCustomerId(), new LevelId($levelId), true)
        );

        return $this->view([]);
    }

    /**
     * Method allows to assign POS to customer.
     *
     * @param Request         $request
     * @param CustomerDetails $customer
     *
     * @return View
     * @Route(name="oloy.customer.assign_pos", path="/customer/{customer}/pos")
     * @Route(name="oloy.customer.seller.assign_pos", path="/seller/customer/{customer}/pos")
     * @Method("POST")
     * @Security("is_granted('ASSIGN_POS', customer)")
     *
     * @ApiDoc(
     *     name="Assign pos to customer",
     *     section="Customer",
     *     parameters={{"name"="posId", "dataType"="string", "required"=true}},
     *     statusCodes={
     *       200="Returned when successful",
     *       400="Returned when posId is not provided or customer does not exist",
     *     }
     * )
     */
    public function assignPosToCustomerAction(Request $request, CustomerDetails $customer)
    {
        $posId = $request->request->get('posId');
        if (!$posId) {
            return $this->view(['posId' => 'field is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->get('broadway.command_handling.command_bus')->dispatch(
            new AssignPosToCustomer($customer->getCustomerId(), new PosId($posId))
        );

        return $this->view([]);
    }

    /**
     * Method allows to deactivate customer<br/>Inactive customer will not be able to log in.
     *
     * @Route(name="oloy.customer.deactivate_customer", path="/admin/customer/{customer}/deactivate")
     * @Route(name="oloy.customer.seller.deactivate_customer", path="/seller/customer/{customer}/deactivate")
     * @Method("POST")
     * @Security("is_granted('DEACTIVATE', customer)")
     *
     * @ApiDoc(
     *     name="Deactivate customer",
     *     section="Customer"
     * )
     *
     * @param CustomerDetails $customer
     *
     * @return View
     */
    public function deactivateCustomerAction(CustomerDetails $customer)
    {
        $this->get('broadway.command_handling.command_bus')->dispatch(
            new DeactivateCustomer($customer->getCustomerId())
        );

        $user = $this->getDoctrine()->getManager()->find(Customer::class, $customer->getCustomerId()->__toString());
        if ($user instanceof User) {
            $user->setIsActive(false);
            $this->get('oloy.user.user_manager')->updateUser($user);
        }
        if ($user instanceof Customer) {
            $user->setStatus(Status::typeBlocked());
            $this->get('oloy.user.user_manager')->updateUser($user);
        }

        return $this->view('');
    }

    /**
     * Method allows to activate customer.
     *
     * @Route(name="oloy.customer.ativate_customer", path="/admin/customer/{customer}/activate")
     * @Route(name="oloy.customer.seller.activate_customer", path="/seller/customer/{customer}/activate")
     * @Method("POST")
     * @Security("is_granted('ACTIVATE', customer)")
     *
     * @ApiDoc(
     *     name="Activate customer",
     *     section="Customer"
     * )
     *
     * @param CustomerDetails $customer
     *
     * @return View
     */
    public function activateCustomerAction(CustomerDetails $customer)
    {
        $this->get('broadway.command_handling.command_bus')->dispatch(
            new ActivateCustomer($customer->getCustomerId())
        );

        $user = $this->getDoctrine()->getManager()->find(Customer::class, $customer->getCustomerId()->__toString());
        if ($user instanceof User) {
            $user->setIsActive(true);
            $this->get('oloy.user.user_manager')->updateUser($user);
        }

        if ($user instanceof Customer) {
            $user->setStatus(Status::typeActiveNoCard());
            $this->get('oloy.user.user_manager')->updateUser($user);
        }

        return $this->view('');
    }

    /**
     * Method allows to activate customer.
     *
     * @Route(name="oloy.customer.send_sms_code_customer", path="/admin/customer/{customer}/send-sms-code")
     * @Route(name="oloy.customer.seller.send_sms_code_customer", path="/seller/customer/{customer}/send-sms-code")
     * @Method("POST")
     * @Security("is_granted('ACTIVATE', customer)")
     *
     * @ApiDoc(
     *     name="Send sms code to customer",
     *     section="Customer"
     * )
     *
     * @param CustomerDetails $customer
     *
     * @return View
     */
    public function sendSmsCodeCustomerAction(CustomerDetails $customer)
    {
        $user = $this->getDoctrine()->getManager()->find(Customer::class, $customer->getCustomerId()->__toString());
        if ($user instanceof Customer && $user->isNew()) {
            $activationCodeManager = $this->get('oloy.activation_code_manager');
            $code = $activationCodeManager->newCode(Customer::class, $customer->getCustomerId()->__toString());
            if (!$code) {
                return $this->view('', Response::HTTP_BAD_REQUEST);
            }
            $activationCodeManager->sendCode($code, $customer->getPhone());
        }

        return $this->view('');
    }

    /**
     * Method allows to activate customer.
     *
     * @Route(name="oloy.customer.send_sms_code_to_customer_by_phone", path="/customer/customer-phone/send-sms-code")
     * @Method("POST")
     * @ApiDoc(
     *     name="Send sms code to customer",
     *     section="Customer"
     * )
     *
     * @return View
     */
    public function resendSmsCodeToCustomerByPhoneAction(Request $request)
    {
        $phone = $request->request->get('phone');
        if (!$phone) {
            return $this->view('', Response::HTTP_BAD_REQUEST);
        }
        $user = $this->getDoctrine()->getManager()->getRepository(Customer::class)
            ->findOneBy(['phone' => $phone]);

        if ($user instanceof Customer && $user->isNew()) {
            $activationCodeManager = $this->get('oloy.activation_code_manager');
            if ($activationCodeManager->resendCode($user)) {
                return $this->view('');
            } else {
                return $this->view('', Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->view('', Response::HTTP_BAD_REQUEST);
    }

    /**
     * Method allows to activate by activation token.
     *
     * @Route(name="oloy.customer.ativate_account", path="/customer/activate/{token}")
     * @Method("POST")
     *
     * @ApiDoc(
     *     name="Activate account",
     *     section="Customer"
     * )
     *
     * @param $token
     * @param RegisterCustomerManager $registerCustomerManager
     *
     * @return View
     */
    public function activateAccountAction($token, RegisterCustomerManager $registerCustomerManager)
    {
        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('OpenLoyaltyUserBundle:Customer')->findOneBy(['actionToken' => $token]);

        if ($user instanceof Customer && $token == $user->getActionToken()) {
            $registerCustomerManager->activate($user);

            return $this->view('', Response::HTTP_OK);
        } else {
            throw new NotFoundHttpException('bad_token');
        }
    }

    /**
     * Method allows to activate by activation token.
     *
     * @Route(name="oloy.customer.activate_sms_account", path="/customer/activate-sms/{token}")
     * @Method("POST")
     *
     * @ApiDoc(
     *     name="Activate account by sms",
     *     section="Customer"
     * )
     *
     * @param $token
     * @param RegisterCustomerManager $registerCustomerManager
     *
     * @return View
     */
    public function activateSmsAccountAction($token, RegisterCustomerManager $registerCustomerManager)
    {
        $code = $this->get('oloy.activation_code_manager')->findValidCode($token, Customer::class);
        if (null === $code) {
            throw new NotFoundHttpException('bad_token');
        }

        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('OpenLoyaltyUserBundle:Customer')->find($code->getObjectId());

        if ($user instanceof Customer && $user->isNew()) {
            $registerCustomerManager->activate($user);

            return $this->view('', Response::HTTP_OK);
        } else {
            throw new NotFoundHttpException('bad_token');
        }
    }

    /**
     * @param SellerId $id
     *
     * @return SellerDetails|null
     */
    protected function getSellerDetails(SellerId $id)
    {
        /** @var Repository $repo */
        $repo = $this->get('oloy.user.read_model.repository.seller_details');

        return $repo->find($id->__toString());
    }

    protected function handleSellerWasACreator(User $loggedUser, $customerId, User $user)
    {
        $sellerDetails = $this->getSellerDetails(new SellerId($loggedUser->getId()));
        if ($sellerDetails instanceof SellerDetails && $sellerDetails->getPosId()) {
            // assign pos and send email
            $this->get('broadway.command_handling.command_bus')->dispatch(
                new AssignPosToCustomer($customerId, new PosId($sellerDetails->getPosId()->__toString()))
            );
        }
    }

    /**
     * Method allows to remove customer from manually assigned level.
     *
     * @param Request         $request
     * @param CustomerDetails $customer
     *
     * @return View
     * @Route(name="oloy.customer.remove_customer_from_manually_assigned_level",
     *     path="/customer/{customer}/remove-manually-level")
     * @Method("POST")
     * @Security("is_granted('ASSIGN_CUSTOMER_LEVEL', customer)")
     *
     * @ApiDoc(
     *     name="Remove customer from manually assigned level",
     *     section="Customer",
     *     statusCodes={
     *       204="Returned when successful",
     *       400="Returned when customer is not assigned to level manually",
     *     }
     * )
     */
    public function removeCustomerFromManuallyAssignedLevelAction(Request $request, CustomerDetails $customer)
    {
        $manuallyAssignedLevelId = $customer->getManuallyAssignedLevelId();
        if (!$manuallyAssignedLevelId) {
            return $this->view(
                ['error' => 'Customer is not assigned to level manually'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->get('broadway.command_handling.command_bus')->dispatch(
            new RemoveManuallyAssignedLevel($customer->getCustomerId())
        );

        return $this->view(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Import customers.
     *
     * @Route(name="oloy.customer.import", path="/admin/customer/import")
     * @Method("POST")
     * @Security("is_granted('CREATE_CUSTOMER')")
     * @ApiDoc(
     *     name="Import customers",
     *     section="Customer",
     *     input={"class" = "OpenLoyalty\Bundle\ImportBundle\Form\Type\ImportFileFormType", "name" = "file"}
     * )
     *
     * @param Request             $request
     * @param CustomerXmlImporter $importer
     * @param ImportFileManager   $importFileManager
     *
     * @return View
     */
    public function importAction(Request $request, CustomerXmlImporter $importer, ImportFileManager $importFileManager)
    {
        $form = $this->get('form.factory')->createNamed('file', ImportFileFormType::class);

        $form->handleRequest($request);

        if ($form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->getData()->getFile();
            $importFile = $importFileManager->upload($file, 'customers');
            $result = $importer->import($importFileManager->getAbsolutePath($importFile));

            return $this->view($result, Response::HTTP_OK);
        }

        return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
    }
}
