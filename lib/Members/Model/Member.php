<?php

namespace Members\Model;

use Pimcore\Model\Object\Concrete;

use Pimcore\Model\Object\Folder;
use Pimcore\Model\Document\Email;
use Members\Model\Configuration;
use Pimcore\Model\WebsiteSetting;

class Member extends Concrete {

    public function register(array $data)
    {
        $argv = compact('data');
        $argv['validateFor'] = 'create';

        $results = \Pimcore::getEventManager()->triggerUntil('members.register.validate',
            $this, $argv, function ($v) {
                return ($v instanceof \Zend_Filter_Input);
            });
        $input = $results->last();

        if (!$input instanceof \Zend_Filter_Input)
        {
            throw new \Exception('No validate listener attached to "members.register.validate" event');
        }

        if (!$input->isValid())
        {
            return $input;
        }

        try
        {
            $this->setValues($input->getUnescaped());

            //@fixme: which userGroup to registered User?
            //$this->getGroups( array() );

            $this->setKey(\Pimcore\File::getValidFilename($this->getEmail()));
            $this->setParent(Folder::getByPath('/' . ltrim(Configuration::get('auth.adapter.objectPath'), '/')));
            $this->save();
            \Pimcore::getEventManager()->trigger('members.register.post', $this, $argv);
        }
        catch (\Exception $e)
        {
            if ($this->getId())
            {
                $this->delete();
            }

            throw $e;
        }

        return $input;
    }

    public function updateProfile(array $data)
    {
        $argv = compact('data');
        $argv['validateFor'] = 'update';

        $results = \Pimcore::getEventManager()->triggerUntil('members.update.validate',
            $this, $argv, function ($v) {
                return ($v instanceof \Zend_Filter_Input);
            });
        $input = $results->last();

        if (!$input instanceof \Zend_Filter_Input)
        {
            throw new \Exception('No validate listener attached to "members.update.validate" event');
        }

        if (!$input->isValid())
        {
            return $input;
        }

        try
        {
            $this->setValues($input->getUnescaped());
            $this->save();
            \Pimcore::getEventManager()->trigger('members.update.post', $this, $argv);
        }
        catch (\Exception $e)
        {
            throw $e;
        }

        return $input;
    }

    public function createHash($algo = 'md5')
    {
        return hash($algo, $this->getId() . $this->getEmail() . mt_rand());
    }

    public function confirm()
    {
        //do not check mandatory fields because of conditional logic!
        $this->setOmitMandatoryCheck(true);
        $this->setPublished(true);
        $this->setConfirmHash(null);
        $this->save();

        //send confirm notification
        if( Configuration::get('sendNotificationMailAfterConfirm') === TRUE )
        {
            /** @var \Pimcore\Model\Document\Email $doc */
            $doc = Email::getByPath( Configuration::getLocalizedPath('emails.registerNotification') );

            if (!$doc)
            {
                throw new \Exception('No register notification email template defined');
            }

            /** @var \Zend_Controller_Request_Http $request */
            $request = \Zend_Controller_Front::getInstance()->getRequest();
            $email = new \Pimcore\Mail();
            $email->setDocument($doc);
            $email->setTo($doc->getTo());
            $email->setParams([
                'host' => sprintf('%s://%s', $request->getScheme(), $request->getHttpHost()),
                'member_id' => $this->getId(),
                'deeplink' => sprintf('%s://%s', $request->getScheme(), $request->getHttpHost()) . '/admin/login/deeplink?object_' . $this->getId() . '_object',
                'member_name' => $this->getLastname() . ' ' . $this->getFirstname(),
            ]);

            $email->send();
        }

        //allow 3rd party plugins to hook into confirm post events.
        \Pimcore::getEventManager()->trigger('members.confirm.post', $this);

        return $this;
    }

    public function requestPasswordReset()
    {
        //do not check mandatory fields because of conditional logic!
        $this->setOmitMandatoryCheck(true);
        $this->setResetHash($this->createHash());
        $this->save();


        $settings = WebsiteSetting::getByName('emailForPasswordReset');

        $configDocument = \Pimcore\Model\Document::getById($settings->getData());
        $folder = $configDocument->getPath();
        $lang = strtoupper($this->getDebtor()->getCountryregioncode()->getCountry_short());
        $fullPath = $folder . $lang;
        $emailTemplate = \Pimcore\Model\Document::getByPath($fullPath);

        if (!$emailTemplate){
            throw new Exception('No order confirmation for language ' . $lang);
        }

        $mail = new \Pimcore\Mail();
        $mail->setDocument($emailTemplate->getId());


        /** @var \Zend_Controller_Request_Http $request */
        $request = \Zend_Controller_Front::getInstance()->getRequest();
        $email = new \Pimcore\Mail();
        $email->addTo($this->getEmail());
        $email->setDocument($emailTemplate->getId());

        $url = sprintf('%s://%s', $request->getScheme(), $request->getHttpHost())
            . '/de/members/password-reset?hash='
            .  $this->getResetHash();

        $params = [
            'host' => sprintf('%s://%s', $request->getScheme(), $request->getHttpHost()),
            'url' => $url,
            'member_id' => $this->getId(),
            'firstname' => $this->getFirstname(),
            'lastname' => $this->getLastname(),
        ];

        $email->setParams($params);
        $email->send();

        return $this;
    }

    public function resetPassword(array $data)
    {
        $argv = compact('data');
        $results = \Pimcore::getEventManager()->triggerUntil('members.password.reset',
            $this, $argv, function ($v) {
                return ($v instanceof \Zend_Filter_Input);
            });

        $input = $results->last();

        if (!$input instanceof \Zend_Filter_Input)
        {
            throw new \Exception('No validate listener attached to "members.password.reset" event');
        }

        if (!$input->isValid())
        {
            return $input;
        }

        //do not check mandatory fields because of conditional logic!
        $this->setOmitMandatoryCheck(true);
        $this->setPassword( $input->getUnescaped('password') );
        $this->setResetHash(null);
        $this->save();

        if (!$this->isPublished())
        {
            $this->confirm();
        }

        return $input;
    }

    public function changePassword(array $data)
    {
        $argv = compact('data');
        $results = \Pimcore::getEventManager()->triggerUntil('members.password.change',
            $this, $argv, function ($v) {
                return ($v instanceof \Zend_Filter_Input);
            });

        $input = $results->last();

        if (!$input instanceof \Zend_Filter_Input)
        {
            throw new \Exception('No validate listener attached to "members.password.change" event');
        }

        if (!$input->isValid())
        {
            return $input;
        }

        //do not check mandatory fields because of conditional logic!
        $this->setOmitMandatoryCheck(true);
        $this->setPassword( $input->getUnescaped('password') );
        $this->save();

        return $input;
    }
}
