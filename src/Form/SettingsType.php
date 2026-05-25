<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Settings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // General Settings
            ->add('siteName', TextType::class, [
                'label' => 'Site Name',
                'attr' => [
                    'placeholder' => 'My Website',
                    'class' => 'form-control',
                    'maxlength' => 255,
                ],
                'help' => 'The name of your website (used in page titles)',
            ])
            ->add('adminPanelTitle', TextType::class, [
                'label' => 'Admin Panel Title',
                'attr' => [
                    'placeholder' => 'My Admin Panel',
                    'class' => 'form-control',
                    'maxlength' => 255,
                ],
                'help' => 'Title displayed in the admin panel navbar',
            ])

            // SEO Settings
            ->add('seoDescription', TextareaType::class, [
                'label' => 'SEO Description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'class' => 'form-control',
                    'placeholder' => 'Description for search engines',
                    'maxlength' => 500,
                ],
                'help' => 'Meta description for SEO (max 500 characters)',
            ])
            ->add('seoKeywords', TextType::class, [
                'label' => 'SEO Keywords',
                'required' => false,
                'attr' => [
                    'placeholder' => 'keyword1, keyword2, keyword3',
                    'class' => 'form-control',
                    'maxlength' => 255,
                ],
                'help' => 'Comma-separated keywords for SEO',
            ])

            // Localization
            ->add('defaultLocale', ChoiceType::class, [
                'label' => 'Default Language',
                'choices' => [
                    'English' => 'en',
                    'French' => 'fr',
                    'German' => 'de',
                    'Spanish' => 'es',
                    'Italian' => 'it',
                ],
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'Default language for the site',
            ])

            // Mode
            ->add('headlessMode', CheckboxType::class, [
                'label' => 'Headless Mode',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input',
                ],
                'help' => 'Enable to disable frontend URLs (API and admin only)',
            ])

            // Test Execution
            ->add('autoReportForIndividualRuns', CheckboxType::class, [
                'label' => 'Auto-generate Allure report for individual test runs',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input',
                ],
                'help' => 'When disabled, Allure reports are only auto-generated for suite runs. Use app:report:generate to create reports manually.',
            ])
            ->add('maxRetryCount', ChoiceType::class, [
                'label' => 'Auto-Retry Failed Tests',
                'choices' => [
                    'Disabled' => 0,
                    '1 retry' => 1,
                    '2 retries' => 2,
                    '3 retries' => 3,
                    '4 retries' => 4,
                    '5 retries' => 5,
                ],
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'Automatically retry tests that fail due to WebDriver/infrastructure errors (timeouts, element interactability). Assertion failures are never retried.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Settings::class,
        ]);
    }
}
