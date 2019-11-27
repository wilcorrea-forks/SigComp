<?php

namespace App\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Model\Avaliacao
 *
 * @ORM\Entity()
 * @ORM\Table(name="avaliacao")
 */
class Avaliacao  
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="date", nullable=false)
     */
    protected $data;

    /**
     * @ORM\ManyToOne(targetEntity="Usuario", inversedBy="avaliacao")
     * @ORM\JoinColumn(name="aluno", referencedColumnName="id", nullable=false)
     */
    protected $aluno;

    /**
     * @ORM\ManyToOne(targetEntity="Turma", inversedBy="avaliacao")
     * @ORM\JoinColumn(name="turma", referencedColumnName="id", nullable=false)
     */
    protected $turma;

    /**
     * @ORM\OneToMany(targetEntity="RespostaAvaliacao", mappedBy="avaliacao")
     * @ORM\JoinColumn(name="avaliacao", referencedColumnName="id", nullable=false)
     */
    protected $respostas_avaliacao;

    public function __construct()
    {
        $this->alunos = new ArrayCollection();
        $this->turmas = new ArrayCollection();
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return Avaliacao
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     * @return Avaliacao
     */
    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

}