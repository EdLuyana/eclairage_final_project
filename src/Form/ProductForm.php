<?php

namespace App\Form;

use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\Season;
use App\Entity\Category;
use App\Entity\Color;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('supplier', EntityType::class, [
                'class'        => Supplier::class,
                'choice_label' => 'name',
                'label'        => 'Fournisseur',
                'placeholder'  => 'Choisir un fournisseur',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('s')
                        ->andWhere('s.isActive = :active')
                        ->setParameter('active', true)
                        ->orderBy('s.name', 'ASC');
                },
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom du produit',
            ])
            ->add('colors', EntityType::class, [
                'class'        => Color::class,
                'choice_label' => 'name',
                'label'        => 'Couleurs',
                'multiple'     => true,
                'expanded'     => true,
                'mapped'       => false,
                'required'     => false,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('co')
                        ->andWhere('co.isActive = :active')
                        ->setParameter('active', true)
                        ->orderBy('co.name', 'ASC');
                },
            ])
            ->add('season', EntityType::class, [
                'class'        => Season::class,
                'choice_label' => function (Season $season) {
                    return sprintf('%s %d', $season->getName(), $season->getYear());
                },
                'label'        => 'Collection',
                'placeholder'  => 'Choisir une collection',
                'required'     => false,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('se')
                        ->andWhere('se.isActive = :active')
                        ->setParameter('active', true)
                        ->orderBy('se.year', 'DESC')
                        ->addOrderBy('se.name', 'ASC');
                },
            ])
            ->add('category', EntityType::class, [
                'class'        => Category::class,
                'choice_label' => 'name',
                'label'        => 'Catégorie',
                'placeholder'  => 'Choisir une catégorie',
                'required'     => false,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('c')
                        ->andWhere('c.isActive = :active')
                        ->setParameter('active', true)
                        ->orderBy('c.name', 'ASC');
                },
            ])
            ->add('price', MoneyType::class, [
                'label'    => 'Prix TTC',
                'currency' => 'EUR',
                'scale'    => 2,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}
