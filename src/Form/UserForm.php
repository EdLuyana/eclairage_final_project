<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var bool $isEdit */
        $isEdit = $options['is_edit'] ?? false;

        if (!$isEdit) {
            $builder->add('username', TextType::class, [
                'label'    => 'Nom d\'utilisateur',
                'required' => true,
            ]);
        }

        $builder->add('plainPassword', RepeatedType::class, [
            'type'            => PasswordType::class,
            'mapped'          => false,
            'required'        => true,
            'first_options'   => ['label' => $isEdit ? 'Nouveau mot de passe' : 'Mot de passe'],
            'second_options'  => ['label' => 'Confirmation du mot de passe'],
            'invalid_message' => 'Les mots de passe ne correspondent pas.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit'    => false,
        ]);
    }
}
