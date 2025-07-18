<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Settings;

use Chamilo\CoreBundle\Form\Type\YesNoType;
use Sylius\Bundle\SettingsBundle\Schema\AbstractSettingsBuilder;
use Symfony\Component\Form\FormBuilderInterface;

class CasSettingsSchema extends AbstractSettingsSchema
{
    public function buildSettings(AbstractSettingsBuilder $builder): void
    {
        $builder
            ->setDefaults(
                [
                    'cas_activate' => '',
                    'cas_server' => '',
                    'cas_server_uri' => '',
                    'cas_port' => '',
                    'cas_protocol' => '',
                    'cas_add_user_activate' => '',
                    'update_user_info_cas_with_ldap' => '',
                ]
            )
        ;
    }

    public function buildForm(FormBuilderInterface $builder): void
    {
        $builder
            ->add('cas_activate', YesNoType::class)
            ->add('cas_server')
            ->add('cas_server_uri')
            ->add('cas_port')
            ->add('cas_protocol')
            ->add('cas_server')
            ->add('cas_add_user_activate')
            ->add('update_user_info_cas_with_ldap', YesNoType::class)
        ;

        $this->updateFormFieldsFromSettingsInfo($builder);
    }
}
