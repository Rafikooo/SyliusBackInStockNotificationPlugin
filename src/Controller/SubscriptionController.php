<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use DateTime;
use Exception;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Form\SubscriptionType;
use Webmozart\Assert\Assert;

final class SubscriptionController extends AbstractController
{
    /** @var RepositoryInterface */
    private $backInStockNotificationRepository;

    /** @var FactoryInterface */
    private $backInStockNotificationFactory;

    /** @var CustomerRepositoryInterface */
    private $customerRepository;

    /** @var LocaleContextInterface */
    private $localeContext;

    /** @var SenderInterface */
    private $sender;

    /** @var ProductVariantRepositoryInterface */
    private $productVariantRepository;

    /** @var AvailabilityCheckerInterface */
    private $availabilityChecker;

    /** @var CustomerContextInterface */
    private $customerContext;

    /** @var ValidatorInterface */
    private $validator;

    /** @var TranslatorInterface */
    private $translator;

    /** @var ChannelContextInterface */
    private $channelContext;

    public function __construct(
        ChannelContextInterface $channelContext,
        TranslatorInterface $translator,
        ValidatorInterface $validator,
        CustomerContextInterface $customerContext,
        AvailabilityCheckerInterface $availabilityChecker,
        ProductVariantRepositoryInterface $productVariantRepository,
        SenderInterface $sender,
        LocaleContextInterface $localeContext,
        CustomerRepositoryInterface $customerRepository,
        RepositoryInterface $backInStockNotificationRepository,
        FactoryInterface $backInStockNotificationFactory
    ) {
        $this->backInStockNotificationRepository = $backInStockNotificationRepository;
        $this->backInStockNotificationFactory = $backInStockNotificationFactory;
        $this->customerRepository = $customerRepository;
        $this->localeContext = $localeContext;
        $this->sender = $sender;
        $this->productVariantRepository = $productVariantRepository;
        $this->availabilityChecker = $availabilityChecker;
        $this->customerContext = $customerContext;
        $this->validator = $validator;
        $this->translator = $translator;
        $this->channelContext = $channelContext;
    }

    public function addAction(Request $request): Response
    {
        $form = $this->createForm(SubscriptionType::class);
        $productVariantCode = $request->query->get('product_variant_code');
        if (is_string($productVariantCode)) {
            $form->setData(['product_variant_code' => $productVariantCode]);
        }

        $customer = $this->customerContext->getCustomer();
        if ($customer !== null && $customer->getEmail() !== null) {
            $form->remove('email');
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_form'));

            return $this->redirect($this->getRefererUrl($request));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $subscription = $this->backInStockNotificationFactory->createNew();
            Assert::implementsInterface($subscription, SubscriptionInterface::class);
            /** @var SubscriptionInterface $subscription */

            if (array_key_exists('email', $data)) {
                $email = $data['email'];
                if (!$email) {
                    $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_form'));

                    return $this->redirect($this->getRefererUrl($request));
                }
                $errors = $this->validator->validate($email, new Email());
                if (count($errors) > 0) {
                    $this->addFlash('error', $errors[0]->getMessage());

                    return $this->redirect($this->getRefererUrl($request));
                }
                $subscription->setEmail($email);
            } elseif ($customer && $customer->getEmail()) {
                $subscription->setCustomer($customer);
                $subscription->setEmail($customer->getEmail());
            } else {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_form'));

                return $this->redirect($this->getRefererUrl($request));
            }

            $variant = $this->productVariantRepository->findOneBy(['code' => $data['product_variant_code']]);
            if (!$variant) {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.variant_not_found'));

                return $this->redirect($this->getRefererUrl($request));
            }
            Assert::implementsInterface($variant, ProductVariantInterface::class);
            /** @var ProductVariantInterface $variant */
            if ($this->availabilityChecker->isStockAvailable($variant)) {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.variant_not_oos'));

                return $this->redirect($this->getRefererUrl($request));
            }

            $subscription->setProductVariant($variant);
            $subscriptionSaved = $this->backInStockNotificationRepository->findOneBy(
                ['email' => $subscription->getEmail(), 'productVariant' => $subscription->getProductVariant()]
            );
            if ($subscriptionSaved) {
                $this->addFlash('error', $this->translator->trans(
                    'webgriffe_bisn.form_submission.already_saved',
                    ['email' => $subscription->getEmail()])
                );

                return $this->redirect($this->getRefererUrl($request));
            }

            $currentChannel = $this->channelContext->getChannel();
            $subscription->setLocaleCode($this->localeContext->getLocaleCode());
            $subscription->setCreatedAt(new DateTime());
            $subscription->setUpdatedAt(new DateTime());
            $subscription->setChannel($currentChannel);

            try {
                //I generate a random string to handle the delete action of the subscription using a GET
                //This way is easier and does not send sensible information
                //see: https://paragonie.com/blog/2015/09/comprehensive-guide-url-parameter-encryption-in-php
                $hash = strtr(base64_encode(random_bytes(9)), '+/', '-_');
            } catch (Exception $e) {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.subscription_failed'));

                return $this->redirect($this->getRefererUrl($request));
            }
            $subscription->setHash($hash);

            $this->backInStockNotificationRepository->add($subscription);
            $this->sender->send(
                'webgriffe_back_in_stock_notification_success_subscription',
                [$subscription->getEmail()],
                [
                    'subscription' => $subscription,
                    'channel' => $subscription->getChannel(),
                    'localeCode' => $subscription->getLocaleCode(),
                ]
            );

            $this->addFlash('success', $this->translator->trans('webgriffe_bisn.form_submission.subscription_successfully'));

            return $this->redirect($this->getRefererUrl($request));
        }

        return $this->render(
            '@WebgriffeSyliusBackInStockNotificationPlugin/productSubscriptionForm.html.twig',
            ['form' => $form->createView(),]
        );
    }

    public function deleteAction(Request $request, string $hash): Response
    {
        $subscription = $this->backInStockNotificationRepository->findOneBy(['hash' => $hash]);
        if ($subscription) {
            Assert::implementsInterface($subscription, SubscriptionInterface::class);
            /** @var SubscriptionInterface $subscription */
            $this->backInStockNotificationRepository->remove($subscription);
            $this->addFlash('info', $this->translator->trans('webgriffe_bisn.deletion_submission.successful'));

            return $this->redirect($this->getRefererUrl($request));
        }
        $this->addFlash('info', $this->translator->trans('webgriffe_bisn.deletion_submission.not-successful'));

        return $this->redirect($this->getRefererUrl($request));
    }

    public function accountListAction(): Response
    {
        $customer = $this->customerContext->getCustomer();
        if ($customer === null) {
            return $this->redirect($this->generateUrl('sylius_shop_login'));
        }

        $subscriptions = $this->backInStockNotificationRepository->findBy(['customer' => $customer]);
        Assert::allImplementsInterface($subscriptions, SubscriptionInterface::class);
        /** @var SubscriptionInterface[] $subscriptions */

        return $this->render('@WebgriffeSyliusBackInStockNotificationPlugin/accountSubscriptionList.html.twig', [
            'subscriptions' => $subscriptions,
        ]);
    }

    private function getRefererUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if (!is_string($referer)) {
            $referer = $this->generateUrl('sylius_shop_homepage');
        }

        return $referer;
    }
}
