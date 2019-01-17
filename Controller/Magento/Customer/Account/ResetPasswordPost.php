<?php

namespace CopeX\AutoLoginAfterPasswordReset\Controller\Magento\Customer\Account;


use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\InputException;
use Magento\Customer\Model\Customer\CredentialsValidator;

class ResetPasswordPost extends \Magento\Customer\Controller\Account\ResetPasswordPost
{
    /**
     * @var \Magento\Quote\Api\CartManagementInterface
     */
    protected $cartManagementInterface;
    protected $searchCriteriaBuilder;

    /**
     * ResetPasswordPost constructor.
     * @param Context                                      $context
     * @param Session                                      $customerSession
     * @param AccountManagementInterface                   $accountManagement
     * @param CustomerRepositoryInterface                  $customerRepository
     * @param \Magento\Quote\Api\CartManagementInterface   $cartManagementInterface
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CredentialsValidator|null                    $credentialsValidator
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        AccountManagementInterface $accountManagement,
        CustomerRepositoryInterface $customerRepository,
        \Magento\Quote\Api\CartManagementInterface $cartManagementInterface,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        CredentialsValidator $credentialsValidator = null
    ) {
        parent::__construct($context, $customerSession, $accountManagement, $customerRepository, $credentialsValidator);
        $this->cartManagementInterface = $cartManagementInterface;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Reset forgotten password
     *
     * Used to handle data received from reset forgotten password form
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $resetPasswordToken = (string)$this->getRequest()->getQuery('token');
        $password = (string)$this->getRequest()->getPost('password');
        $passwordConfirmation = (string)$this->getRequest()->getPost('password_confirmation');

        if ($password !== $passwordConfirmation) {
            $this->messageManager->addErrorMessage(__("New Password and Confirm New Password values didn't match."));
            $resultRedirect->setPath(
                '*/*/createPassword',
                ['token' => $resetPasswordToken]
            );
            return $resultRedirect;
        }
        if (iconv_strlen($password) <= 0) {
            $this->messageManager->addErrorMessage(__('Please enter a new password.'));
            $resultRedirect->setPath(
                '*/*/createPassword',
                ['token' => $resetPasswordToken]
            );
            return $resultRedirect;
        }

        try {
            $customer = $this->matchCustomerByRpToken($resetPasswordToken);
            $this->accountManagement->resetPassword(
                $customer->getEmail(),
                $resetPasswordToken,
                $password
            );
            $this->session->unsRpToken();
            $this->session->start();
            $this->session->setCustomerDataAsLoggedIn($customer);
            $cart = $this->cartManagementInterface->getCartForCustomer($customer->getId());
            if (count($cart->getItems())) {
                $resultRedirect->setPath("checkout/cart/index");
            }
            else{
                $resultRedirect->setPath('*/*/index');
            }
            $this->messageManager->addSuccessMessage(__('You updated your password.'));
            return $resultRedirect;
        } catch (InputException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            foreach ($e->getErrors() as $error) {
                $this->messageManager->addErrorMessage($error->getMessage());
            }
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage(__('Something went wrong while saving the new password.'));
        }

        $resultRedirect->setPath(
            '*/*/createPassword',
            ['token' => $resetPasswordToken]
        );
        return $resultRedirect;
    }

    /**
     * @param string $rpToken
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\State\ExpiredException
     */
    private function matchCustomerByRpToken(string $rpToken): \Magento\Customer\Api\Data\CustomerInterface
    {

        $this->searchCriteriaBuilder->addFilter(
            'rp_token',
            $rpToken
        );
        $this->searchCriteriaBuilder->setPageSize(1);
        $found = $this->customerRepository->getList(
            $this->searchCriteriaBuilder->create()
        );

        if ($found->getTotalCount() > 1) {
            //Failed to generated unique RP token
            throw new \Magento\Framework\Exception\State\ExpiredException(
                new \Magento\Framework\Phrase('Reset password token expired.')
            );
        }
        if ($found->getTotalCount() === 0) {
            //Customer with such token not found.
            throw \Magento\Framework\Exception\NoSuchEntityException::singleField(
                'rp_token',
                $rpToken
            );
        }

        //Unique customer found.
        return $found->getItems()[0];
    }
}
	
	