<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Cell Parameters Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This stuff is an implementation of crystallographic space groups, which has been a "solved"
 * problem since the 1890's.  Roughly speaking, it's a hierarchy where space group implies a point
 * group, which implies a crystal system.  You can specify a crystal system without the other two,
 * and can also specify a point group without a space group (very rare)...but it doesn't work in
 * the other direction.
 *
 * The trick is that there is usually more than one way to "denote" a space group, depending on which
 * of the crystal's axes you choose for a/b/c, and by extension alpha/beta/gamma. For instance,
 * "P1", "A1", "B1", "C1", "I1", and "F1" all denote space group #1...but "P2" is the only valid
 * way to denote space group #3.
 *
 * While this could be accomplished via ODR's tag system, doing so would be quite irritating.
 * RRUFF had a system where selecting a crystal system hid all point/space groups that didn't belong
 * to said crystal system, and selecting a point group also hid all space groups that didn't belong
 * to said point group.  This effectively eliminated the possibility of entering invalid data for
 * those three fields, while also making it easier to find what you wanted.
 *
 *
 * Additionally, the references these values were pulled from on RRUFF weren't guaranteed to specify
 * the volume parameter...don't know whether it was out of laziness or an inability to calculate.
 * Regardless, the volume is pretty useful, so RRUFF (and this plugin) calculate the volume from
 * the a/b/c/α/β/γ values if needed.  It's only an approximation, however, because significant
 * figures are ignored...
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
use ODR\AdminBundle\Entity\Boolean as ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\PostMassEditEvent;
use ODR\AdminBundle\Component\Event\PostUpdateEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\PostMassEditEventInterface;
use ODR\OpenRepository\GraphBundle\Plugins\TableResultsOverrideInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class RRUFFCellParametersPlugin implements DatatypePluginInterface, DatafieldDerivationInterface, DatafieldReloadOverrideInterface, PostMassEditEventInterface, TableResultsOverrideInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatabaseInfoService
     */
    private $dbi_service;

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var EntityMetaModifyService
     */
    private $emm_service;

    /**
     * @var LockService
     */
    private $lock_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * Defines the 8 official crystal systems...
     *
     * @var string[]
     */
    public $crystal_systems = array(
        'cubic',
        'hexagonal',
//        'rhombohedral',    // Only really used in Europe, see below
        'tetragonal',
        'orthorhombic',
        'monoclinic',
        'triclinic',
        'amorphous',
        'unknown',
    );

    /**
     * Defines the 32 official crystallographic point groups in terms of which crystal system they
     * belong to.  The names of the point groups were given to me by Bob Downs, so blame him if
     * they're non-standard...any differences are most likely because he's focused more on mineralogy.
     *
     * @var array
     */
    public $point_groups = array(
        'cubic' => array('-3-32/m', '332', '4/m-32/m', '432', '-43m'),

        // Europe typically uses 'hexagonal' to refer to point groups starting with 6, and
        //  'rhomobohedral' or 'trigonal' for those starting with 3...since this is based in the
        //  US, we don't really care about the distinction and use 'hexagonal' for those starting
        //  with both 3 and 6 =P
        'hexagonal' => array('3', '-3', '-32/m2/m', '322', '3mm', '6', '-6', '6/m', '6/m2/m2/m', '622', '-62m', '6mm'),
//        'rhombohedral' => array('3', '-3', '322', '-32/m2/m', '3mm'),

        'tetragonal' => array('4', '-4', '4/m', '4/m2/m2/m', '422', '-42m', '4mm'),
        'orthorhombic' => array('2/m2/m2/m', '222', '2mm'),
        'monoclinic' => array('2', '2/m', 'm'),
        'triclinic' => array('1', '-1'),

        // amorphous/unknown don't have point groups
        'amorphous' => array(),
        'unknown' => array(),
    );


    /**
     * For ease of filtering space groups in the popup, also have a "point group" => "space group num"
     * mapping.
     *
     * @var string[]
     */
    public $space_group_mapping = array(
        // triclinic
        '1' => array(1),
        '-1' => array(2),
        // monoclinic
        '2' => array(3, 4, 5),
        'm' => array(6, 7, 8, 9),
        '2/m' => array(10, 11, 12, 13, 14, 15),
        // orthorhombic
        '222' => array(16, 17, 18, 19, 20, 21, 22, 23, 24),
        '2mm' => array(25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46),
        '2/m2/m2/m' => array(47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74),
        // tetragonal
        '4' => array(75, 76, 77, 78, 79, 80),
        '-4' => array(81, 82),
        '4/m' => array(83, 84, 85, 86, 87, 88),
        '422' => array(89, 90, 91, 92, 93, 94, 95, 96, 97, 98),
        '4mm' => array(99, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110),
        '-42m' => array(111, 112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122),
        '4/m2/m2/m' => array(123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133, 134, 135, 136, 137, 138, 139, 140, 141, 142),
        // hexagonal (also known as trigonal/rhomobohedral outside the US)
        '3' => array(143, 144, 145, 146),
        '-3' => array(147, 148),
        '322' => array(149, 150, 151, 152, 153, 154, 155),
        '3mm' => array(156, 157, 158, 159, 160, 161),
        '-32/m2/m' => array(162, 163, 164, 165, 166, 167),
        // hexagonal (everywhere)
        '6' => array(168, 169, 170, 171, 172, 173),
        '-6' => array(174),
        '6/m' => array(175, 176),
        '622' => array(177, 178, 179, 180, 181, 182),
        '6mm' => array(183, 184, 185, 186),
        '-62m' => array(187, 188, 189, 190),
        '6/m2/m2/m' => array(191, 192, 193, 194),
        // cubic
        '332' => array(195, 196, 197, 198, 199),
        '-3-32/m' => array(200, 201, 202, 203, 204, 205, 206),
        '432' => array(207, 208, 209, 210, 211, 212, 213, 214),
        '-43m' => array(215, 216, 217, 218, 219, 220),
        '4/m-32/m' => array(221, 222, 223, 224, 225, 226, 227, 228, 229, 230),
    );


    /**
     * Defines the 230 official crystallographic space groups by their official number, along with
     * (some of) the acceptable labels for said space groups..."P1" and "A1" both mean space group
     * #1, for instance.
     *
     * Roughly speaking, the choice of which space group label gets actually used depends on which
     * axes/angles get assigned to the a/b/c/α/β/γ values.  It's a convenience feature, mostly.
     *
     * @var array[]
     */
    public $space_groups = array(
        1 => array('P1', 'A1', 'B1', 'C1', 'I1', 'F1'),
        2 => array('P-1', 'A-1', 'B-1', 'C-1', 'I-1', 'F-1'),
        3 => array('P2'),
        4 => array('P2_1'),
        5 => array('A2', 'A2_1', 'B2', 'B2_1', 'C2', 'C2_1'),
        6 => array('Pm'),
        7 => array('Pa', 'Pb', 'Pc'),
        8 => array('Am', 'Ab', 'Ac', 'Bm', 'Ba', 'Bc', 'Cm', 'Ca', 'Cb'),
        9 => array('Aa', 'An', 'Bb', 'Bn', 'Cc', 'Cn'),
        10 => array('P2/m'),
        11 => array('P2_1/m'),
        12 => array('A2/m', 'A2_1/b', 'A2_1/c', 'B2/m', 'B2_1/a', 'B2_1/c', 'C2/m', 'C2_1/a', 'C2_1/b'),
        13 => array('P2/a', 'P2/b', 'P2/c'),
        14 => array('P2_1/a', 'P2_1/b', 'P2_1/c', 'P2_1/n'),
        15 => array('A2/a', 'A2_1/n', 'B2/b', 'B2_1/n', 'C2/c', 'C2_1/n'),
        16 => array('P222'),
        17 => array('P222_1', 'P22_12', 'P2_122'),
        18 => array('P2_12_12', 'P2_122_1', 'P22_12_1'),
        19 => array('P2_12_12_1'),
        20 => array('A2_122', 'B22_12', 'C222_1', 'A2_12_12_1', 'B2_12_12_1', 'C2_12_12_1'),
        21 => array('A222', 'B222', 'C222', 'A22_12_1', 'B2_122_1', 'C2_12_12'),
        22 => array('F222', 'F2_12_12_1'),
        23 => array('I222'),
        24 => array('I2_12_12_1'),
        25 => array('P2mm', 'Pm2m', 'Pmm2'),
        26 => array('Pmc2_1', 'P2_1ma', 'Pb2_1m', 'Pm2_1b', 'Pcm2_1', 'P2_1am'),
        27 => array('Pcc2', 'P2aa', 'Pb2b'),
        28 => array('Pma2', 'P2mb', 'Pc2m', 'Pm2a', 'Pbm2', 'P2cm'),
        29 => array('Pca2_1', 'P2_1ab', 'Pc2_1b', 'Pb2_1a', 'Pbc2_1', 'P2_1ca'),
        30 => array('Pnc2', 'P2na', 'Pb2n', 'Pn2b', 'Pcn2', 'P2an'),
        31 => array('Pmn2_1', 'P2_1mn', 'Pn2_1m', 'Pm2_1n', 'Pnm2_1', 'P2_1nm'),
        32 => array('Pba2', 'P2cb', 'Pc2a'),
        33 => array('Pna2_1', 'P2_1nb', 'Pc2_1n', 'Pn2_1a', 'Pbn2_1', 'P2_1cn'),
        34 => array('Pnn2', 'P2nn', 'Pn2n'),
        35 => array('Cmm2', 'A2mm', 'Bm2m', 'Cba2', 'A2cb', 'Bc2a'),
        36 => array('A2_1am', 'A2_1ma', 'A2_1cn', 'A2_1nb', 'Bb2_1m', 'Bm2_1b', 'Bn2_1a', 'Bc2_1n', 'Ccm2_1', 'Cmc2_1', 'Cbn2_1', 'Cna2_1'),
        37 => array('A2aa', 'A2nn', 'Bb2b', 'Bn2n', 'Ccc2', 'Cnn2;'),
        38 => array('Amm2', 'Am2m', 'Anc2_1', 'An2_1b', 'B2mm', 'Bmm2', 'B2_1na', 'Bcn2_1', 'C2mm', 'Cm2m', 'Cb2_1n', 'C2_1an'),
        39 => array('Abm2', 'Ac2m', 'Acc2_1', 'Ab2_1b', 'B2cm', 'Bma2', 'B2_1aa', 'Bcc2_1', 'Cm2a', 'C2mb', 'Cb2_1b', 'C2_1aa'),
        40 => array('Ama2', 'Am2a', 'Ann2_1', 'An2_1n', 'B2mb', 'Bbm2', 'B2_1nn', 'Bnn2_1', 'Cc2m', 'C2cm', 'Cn2_1n', 'C2_1nn'),
        41 => array('Aba2', 'Ac2a', 'Acn2_1', 'Ab2_1n', 'B2cb', 'Bba2', 'B2_1an', 'Bnc2_1', 'Cc2a', 'C2cb', 'Cn2_1b', 'C2_1na'),
        42 => array('Fmm2', 'Fbc2_1', 'Fca2_1', 'Fnn2', 'Fm2m', 'Fb2_1a', 'Fc2_1b', 'Fn2n'),
        43 => array('Fdd2', 'Fdd2_1', 'F2dd', 'F2_1dd', 'Fd2d', 'Fd2_1d'),
        44 => array('Imm2', 'Inn2_1', 'I2mm', 'I2_1nn', 'Im2m', 'In2_1n'),
        45 => array('Iba2', 'Icc2_1', 'I2cb', 'I2_1aa', 'Ic2a', 'Ib2_1b'),
        46 => array('Ima2', 'Inc2_1', 'I2mb', 'I2_1na', 'Ic2m', 'Ib2_1n', 'Im2a', 'In2_1b', 'Ibm2', 'Icn2_1', 'I2cm', 'I2_1an'),
        47 => array('Pmmm'),
        48 => array('Pnnn'),
        49 => array('Pccm', 'Pmaa', 'Pbmb'),
        50 => array('Pban', 'Pncb', 'Pcna'),
        51 => array('Pmma', 'Pbmm', 'Pmcm', 'Pmam', 'Pmmb', 'Pcmm'),
        52 => array('Pnna', 'Pbnn', 'Pncn', 'Pnan', 'Pnnb', 'Pcnn'),
        53 => array('Pmna', 'Pbmn', 'Pncm', 'Pman', 'Pnmb', 'Pcnm'),
        54 => array('Pcca', 'Pbaa', 'Pbcb', 'Pbab', 'Pccb', 'Pcaa'),
        55 => array('Pbam', 'Pmcb', 'Pcma'),
        56 => array('Pccn', 'Pnaa', 'Pbnb'),
        57 => array('Pbcm', 'Pmca', 'Pbma', 'Pcmb', 'Pcam', 'Pmab'),
        58 => array('Pnnm', 'Pmnn', 'Pnmn'),
        59 => array('Pmmn', 'Pnmm', 'Pmnm'),
        60 => array('Pbcn', 'Pnca', 'Pbna', 'Pcnb', 'Pcan', 'Pnab'),
        61 => array('Pbca', 'Pcab'),
        62 => array('Pnma', 'Pbnm', 'Pmcn', 'Pnam', 'Pmnb', 'Pcmn'),
        63 => array('Cmcm', 'Cbnn', 'Amma', 'Ancn', 'Bbmm', 'Bnna', 'Bmmb', 'Bcnn', 'Ccmm', 'Cnan', 'Amam', 'Annb'),
        64 => array('Cmca', 'Cbnb', 'Abma', 'Accn', 'Bbcm', 'Bnaa', 'Bmab', 'Bccn', 'Ccmb', 'Cnaa', 'Acam', 'Abnb', 'Bbam', 'Bmcb'),
        65 => array('Cmmm', 'Cban', 'Ammm', 'Ancb', 'Bmmm', 'Bcna'),
        66 => array('Cccm', 'Cnnn', 'Amaa', 'Annn', 'Bbmb', 'Bnnn'),
        67 => array('Cmma', 'Cbab', 'Abmm', 'Accb', 'Bmcm', 'Bcaa', 'Bmam', 'Bcca', 'Cmmb', 'Cbaa', 'Acmm', 'Abcb'),
        68 => array('Ccca', 'Cnnb', 'Abaa', 'Acnn', 'Bbcb', 'Anan', 'Bbab', 'Bncn', 'Cccb', 'Cnna', 'Acaa', 'Abnn'),
        69 => array('Fmmm', 'Fbca', 'Fcab', 'Fnnn'),
        70 => array('Fddd'),
        71 => array('Immm', 'Innn'),
        72 => array('Ibam', 'Iccn', 'Imcb', 'Inaa', 'Icma', 'Ibnb'),
        73 => array('Ibca', 'Icab'),
        74 => array('Imma', 'Innb', 'Ibmm', 'Icnn', 'Imcm', 'Inan', 'Imam', 'Incn', 'Immb', 'Inna', 'Icmm', 'Ibnn'),
        75 => array('P4', 'C4'),
        76 => array('P4_1', 'C4_1'),
        77 => array('P4_2', 'C4_2'),
        78 => array('P4_3', 'C4_3'),
        79 => array('I4', 'F4'),
        80 => array('I4_1', 'F4_1'),
        81 => array('P-4', 'C-4'),
        82 => array('I-4', 'F-4'),
        83 => array('P4/m', 'C4/m'),
        84 => array('P4_2/m', 'C4_2/m'),
        85 => array('P4/n', 'C4/n'),
        86 => array('P4_2/n', 'C4_2/n'),
        87 => array('I4/m', 'F4/m'),
        88 => array('I4_1/a', 'F4_1/a'),
        89 => array('P422', 'C422'),
        90 => array('P42_12', 'C422_1'),
        91 => array('P4_122', 'C4_122'),
        92 => array('P4_12_12', 'C4_122_1'),
        93 => array('P4_222', 'C4_222'),
        94 => array('P4_22_12', 'C4_222_1'),
        95 => array('P4_322', 'C4_322'),
        96 => array('P4_32_12', 'C4_322_1'),
        97 => array('I422', 'F422'),
        98 => array('I4_122', 'F4_122'),
        99 => array('P4mm', 'C4mm'),
        100 => array('P4bm', 'C4mb'),
        101 => array('P4_2cm', 'C4_2mc'),
        102 => array('P4_2nm', 'C4_2mn'),
        103 => array('P4cc', 'C4cc'),
        104 => array('P4nc', 'C4cn'),
        105 => array('P4_2mc', 'C4_2cm'),
        106 => array('P4_2bc', 'C4_2cb'),
        107 => array('I4mm', 'F4mm'),
        108 => array('I4cm', 'F4mc'),
        109 => array('I4_1md', 'F4_1dm'),
        110 => array('I4_1cd', 'F4_1dc'),
        111 => array('P-42m', 'C-4m2'),
        112 => array('P-42c', 'C-4c2'),
        113 => array('P-42_1m', 'C-4m2_1'),
        114 => array('P-42_1c', 'C-4c2_1'),
        115 => array('P-4m2', 'C-42m'),
        116 => array('P-4c2', 'C-42c'),
        117 => array('P-4bc', 'C-42b'),
        118 => array('P-4n2', 'C-42n'),
        119 => array('I-4m2', 'F-42m'),
        120 => array('I-4c2', 'F-42c'),
        121 => array('I-42m', 'F-4m2'),
        122 => array('I-42d', 'F-4d2'),
        123 => array('P4/mmm', 'C4/mmm', 'P4/m2/m2/m', 'C4/m2/m2/m'),
        124 => array('P4/mcc', 'C4/mcc', 'P4/m2/c2/c', 'C4/m2/c2/c'),
        125 => array('P4/nbm', 'C4/nmb', 'P4/n2/b2/m', 'C4/n2/m2/b'),
        126 => array('P4/nnc', 'C4/ncn', 'P4/n2/n2/c', 'C4/n2/c2/n'),
        127 => array('P4/mbm', 'C4/mmb', 'P4/m2_1/b2/m', 'C4/m2/m2_1/b'),
        128 => array('P4/mnc', 'C4/mcn', 'P4/m2_1/n2/c', 'C4/m2/c2_1/n'),
        129 => array('P4/nmm', 'C4/nmm', 'P4/n2_1/m2/m', 'C4/n2/m2_1/m'),
        130 => array('P4/ncc', 'C4/ncc', 'P4/n2_1/c2/c', 'C4/n2/c2_1/c'),
        131 => array('P4_2/mmc', 'C4_2/mcm', 'P4_2/m2/m2/c', 'C4_2/m2/c2/m'),
        132 => array('P4_2/mcm', 'C4_2/mmc', 'P4_2/m2/c2/m', 'C4_2/m2/m2/c'),
        133 => array('P4_2/nbc', 'C4_2/ncb', 'P4_2/n2/b2/c', 'C4_2/n2/c2/b'),
        134 => array('P4_2/nnm', 'C4_2/nmn', 'P4_2/n2/n2/m', 'C4_2/n2/m2/n'),
        135 => array('P4_2/mbc', 'C4_2/mcb', 'P4_2/m2_1/b2/c', 'C4_2/m2/c2_1/b'),
        136 => array('P4_2/mnm', 'C4_2/mmn', 'P4_2/m2_1/n2/m', 'C4_2/m2/m2_1/n'),
        137 => array('P4_2/nmc', 'C4_2/ncm', 'P4_2/n2_1/m2/c', 'C4_2/n2/c2_1/m'),
        138 => array('P4_2/ncm', 'C4_2/nmc', 'P4_2/n2_1/c2/m', 'C4_2/n2/m2_1/c'),
        139 => array('I4/mmm', 'F4/mmm', 'I4/m2/m2/m', 'F4/m2/m2/m'),
        140 => array('I4/mcm', 'F4/mmc', 'I4/m2/c2/m', 'F4/m2/m2/c'),
        141 => array('I4_1/amd', 'F4_1/adm', 'I4_1/a2/m2/d', 'F4_1/a2/d2/m'),
        142 => array('I4_1/acd', 'F4_1/adc', 'I4_1/a2/c2/d', 'F4_1/a2/d2/c'),
        143 => array('P3'),
        144 => array('P3_1'),
        145 => array('P3_2'),
        146 => array('R3'),
        147 => array('P-3'),
        148 => array('R-3'),
        149 => array('P312'),
        150 => array('P321'),
        151 => array('P3_112'),
        152 => array('P3_121'),
        153 => array('P3_212'),
        154 => array('P3_221'),
        155 => array('R32'),
        156 => array('P3m1'),
        157 => array('P31m'),
        158 => array('P3c1'),
        159 => array('P31c'),
        160 => array('R3m'),
        161 => array('R3c'),
        162 => array('P-31m', 'P-312/m'),
        163 => array('P-31c', 'P-312/c'),
        164 => array('P-3m1', 'P-32/m1'),
        165 => array('P-3c1', 'P-32/c1'),
        166 => array('R-3m', 'R-32/m'),
        167 => array('R-3c', 'R-32/c'),
        168 => array('P6'),
        169 => array('P6_1'),
        170 => array('P6_5'),
        171 => array('P6_2'),
        172 => array('P6_4'),
        173 => array('P6_3'),
        174 => array('P-6'),
        175 => array('P6/m'),
        176 => array('P6_3/m'),
        177 => array('P622'),
        178 => array('P6_122'),
        179 => array('P6_522'),
        180 => array('P6_222'),
        181 => array('P6_422'),
        182 => array('P6_322'),
        183 => array('P6mm'),
        184 => array('P6cc'),
        185 => array('P6_3cm'),
        186 => array('P6_3mc'),
        187 => array('P-6m2'),
        188 => array('P-6c2'),
        189 => array('P-62m'),
        190 => array('P-62c'),
        191 => array('P6/mmm', 'P6/m2/m2/m'),
        192 => array('P6/mcc', 'P6/m2/c2/c'),
        193 => array('P6_3/mcm', 'P6_3/m2/c2/m'),
        194 => array('P6_3/mmc', 'P6_3/m2/m2/c'),
        195 => array('P23'),
        196 => array('F23'),
        197 => array('I23'),
        198 => array('P2_13'),
        199 => array('I2_13'),
        200 => array('Pm3', 'P2/m-3'),
        201 => array('Pn3', 'P2/n-3'),
        202 => array('Fm3', 'F2/m-3'),
        203 => array('Fd3', 'F2/d-3'),
        204 => array('Im3', 'I2/m-3'),
        205 => array('Pa3', 'P2_1/a-3'),
        206 => array('Ia3', 'I2_1/a-3'),
        207 => array('P432'),
        208 => array('P4_232'),
        209 => array('F432'),
        210 => array('F4_132'),
        211 => array('I432'),
        212 => array('P4_332'),
        213 => array('P4_132'),
        214 => array('I4_132'),
        215 => array('P-43m'),
        216 => array('F-43m'),
        217 => array('I-43m'),
        218 => array('P-43n'),
        219 => array('F-43c'),
        220 => array('I-43d'),
        221 => array('Pm3m', 'P4/m-32/m'),
        222 => array('Pn3n', 'P4/n-32/n'),
        223 => array('Pm3n', 'P4_1/m-32/n'),
        224 => array('Pn3m', 'P4_1/n-32/m'),
        225 => array('Fm3m', 'F4/m-32/m'),
        226 => array('Fm3c', 'F4/m-32/c'),
        227 => array('Fd3m', 'F4_1/d-32/m'),
        228 => array('Fd3c', 'F4_1/d-32/c'),
        229 => array('Im3m', 'I4/m-32/m'),
        230 => array('Ia3d', 'I4_1/a-32/d', 'Ia-3d'),
    );


    /**
     * RRUFF Cell Parameters Plugin constructor
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param EntityCreationService $entity_creation_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param LockService $lock_service
     * @param SearchCacheService $search_cache_service
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        EntityCreationService $entity_creation_service,
        EntityMetaModifyService $entity_meta_modify_service,
        LockService $lock_service,
        SearchCacheService $search_cache_service,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dbi_service = $database_info_service;
        $this->dri_service = $datarecord_info_service;
        $this->ec_service = $entity_creation_service;
        $this->emm_service = $entity_meta_modify_service;
        $this->lock_service = $lock_service;
        $this->search_cache_service = $search_cache_service;
        $this->token_manager = $token_manager;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // The render plugin overrides how the user enters the crystal system, point group, and
        //  space group values...it also derives the value for the lattice field, and attempts to
        //  calculate volume if needed...
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'fake_edit'
                || $context === 'display'
                || $context === 'edit'
                || $context === 'mass_edit'
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Executes the RRUFF Cell Parameters Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {

        try {
            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return '';


            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $plugin_fields = array();
            $field_values = array();

            // Want to locate the values for most of the mapped datafields
            $optional_fields = array(
                'Cell Parameter ID' => 0,    // this one can be non-public

                // These four only exist to make the data easier to import
                'Chemistry' => 0,
                'Pressure' => 0,
                'Temperature' => 0,
                'Notes' => 0
            );

            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                // Need to tweak display parameters for several of the fields...
                $plugin_fields[$rpf_df_id] = $rpf_df;
                $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null) {
                    // Optional fields don't have to exist for this plugin to work
                    if ( isset($optional_fields[$rpf_name]) )
                        continue;

                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // Technically, the only time when the plugin shouldn't execute is when any of the
                    //  Crystal System/Point Group/Space Group fields don't exist, and the user is
                    //  in Edit mode...and that's already handled by RRUFFCellparamsController...

                    if ( !$is_datatype_admin )
                        // ...but there are zero compelling reasons to run the plugin if something is missing
                        return '';
                    else
                        // ...if a datatype admin is seeing this, then they need to fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');
                }
                else {
                    // The non-optional fields really should all be public...so actually throw an
                    //  error if any of them aren't and the user can do something about it
                    if ( isset($optional_fields[$rpf_name]) )
                        continue;

                    // If the datafield is non-public...
                    $df_public_date = ($df['dataFieldMeta']['publicDate'])->format('Y-m-d H:i:s');
                    if ( $df_public_date == '2200-01-01 00:00:00' ) {
                        if ( !$is_datatype_admin )
                            // ...but the user can't do anything about it, then just refuse to execute
                            return '';
                        else
                            // ...the user can do something about it, so they need to fix it
                            throw new \Exception('The field "'.$rpf_name.'" is not public...all fields which are a part of this render plugin MUST be public.');
                    }
                }

                // Might need to reference the values of each of these fields
                switch ($rpf_name) {
                    case 'Crystal System':
                    case 'Point Group':
                    case 'Space Group':
                    case 'a':
                    case 'b':
                    case 'c':
                    case 'alpha':
                    case 'beta':
                    case 'gamma':
                    case 'Volume':
                        $value = '';
                        if ( isset($datarecord['dataRecordFields'][$rpf_df_id]['shortVarchar'][0]['value']) )
                            $value = $datarecord['dataRecordFields'][$rpf_df_id]['shortVarchar'][0]['value'];
                        $field_values[$rpf_name] = $value;
                        break;
                }
            }


            // ----------------------------------------
            // Need to check the derived fields so that any problems with them can get displayed
            //  to the user
            $relevant_fields = self::getRelevantFields($datatype, $datarecord);

            $problem_fields = array();
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'edit' ) {
                // Need to check for derivation problems first...
                $derivation_problems = self::findDerivationProblems($relevant_fields);

                // Can't use array_merge() since that destroys the existing keys
                $problem_fields = array();
                foreach ($derivation_problems as $df_id => $problem)
                    $problem_fields[$df_id] = $problem;
            }


            // ----------------------------------------
            // Otherwise, output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                // Point Groups and Space Groups should be modified with CSS for display mode
                if ( isset($field_values['Point Group']) )
                    $field_values['Point Group'] = self::applySymmetryCSS($field_values['Point Group']);
                if ( isset($field_values['Space Group']) )
                    $field_values['Space Group'] = self::applySymmetryCSS($field_values['Space Group']);

                // If the user hasn't entered a value for volume...
                if ( !isset($field_values['Volume']) || $field_values['Volume'] === '' ) {
                    // ...and all six cell parameter values exist...
                    if ( !($field_values['a'] === '' || $field_values['b'] === '' || $field_values['c'] === ''
                        || $field_values['alpha'] === '' || $field_values['beta'] === '' || $field_values['gamma'] === '')
                    ) {
                        // ...then calculate (an approximation of) the volume
                        $field_values['Volume'] = self::calculateVolume($field_values['a'], $field_values['b'], $field_values['c'], $field_values['alpha'], $field_values['beta'], $field_values['gamma']);
                    }
                }

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_display_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord' => $datarecord,
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'is_datatype_admin' => $is_datatype_admin,

                        'plugin_fields' => $plugin_fields,
                        'problem_fields' => $problem_fields,

                        'field_values' => $field_values,
                    )
                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                // Going to need several field identifiers, so all the symmetry fields can be saved
                //  at the same time via the popup
                $field_identifiers = array(
                    'Crystal System' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Crystal System']['id'],
                    'Point Group' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Point Group']['id'],
                    'Space Group' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Space Group']['id'],
                    'Lattice' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Lattice']['id'],
                );

                // Tweak the point group and space group arrays so that they're in order...they're
                //  not defined in order, because it's considerably easier to fix any problems with
                //  them when they're arranged by category instead of by name
                $point_groups = self::sortPointGroups();
                $space_groups = self::sortSpaceGroups();

                // Also going to need a token for the custom form submission, since it uses its
                //  own controller action...
                $token_id = 'RRUFFCellParams_'.$initial_datatype_id.'_'.$datarecord['id'];
                $token_id .= '_'.$relevant_fields['Crystal System']['id'];
                $token_id .= '_'.$relevant_fields['Point Group']['id'];
                $token_id .= '_'.$relevant_fields['Space Group']['id'];
                $token_id .= '_Form';
                $form_token = $this->token_manager->getToken($token_id)->getValue();

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_edit_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord_array' => array($datarecord['id'] => $datarecord),
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,

                        'plugin_fields' => $plugin_fields,
                        'problem_fields' => $problem_fields,

                        'field_identifiers' => $field_identifiers,
                        'form_token' => $form_token,

                        'crystal_systems' => $this->crystal_systems,
                        'point_groups' => $point_groups,
                        'space_groups' => $space_groups,
                    )
                );
            }
            else if ( $rendering_options['context'] === 'fake_edit' ) {
                // Need to provide a special token so these fields won't get ignored by FakeEdit,
                //  because each of them prevent direct user edits...
                $prevent_user_edit_df_ids = array(
                    $fields['Cell Parameter ID']['id'],
                    $fields['Crystal System']['id'],
                    $fields['Point Group']['id'],
                    $fields['Space Group']['id'],
                );
                foreach ($prevent_user_edit_df_ids as $df_id) {
                    $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$df_id.'_autogenerated';
                    $token = $this->token_manager->getToken($token_id)->getValue();

                    $special_tokens[$df_id] = $token;
                }

                // Going to need several field identifiers, so all the symmetry fields can be saved
                //  at the same time via the popup
                $field_identifiers = array(
                    'Crystal System' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Crystal System']['id'],
                    'Point Group' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Point Group']['id'],
                    'Space Group' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Space Group']['id'],
                    'Lattice' => 'ShortVarcharForm_'.$datarecord['id'].'_'.$relevant_fields['Lattice']['id'],
                );

                // Tweak the point group and space group arrays so that they're in order...they're
                //  not defined in order, because it's considerably easier to fix any problems with
                //  them when they're arranged by category instead of by name
                $point_groups = self::sortPointGroups();
                $space_groups = self::sortSpaceGroups();

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_fakeedit_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord_array' => array($datarecord['id'] => $datarecord),
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,
                        'special_tokens' => $special_tokens,
                        'form_token' => '',

                        'plugin_fields' => $plugin_fields,
                        'field_identifiers' => $field_identifiers,

                        'crystal_systems' => $this->crystal_systems,
                        'point_groups' => $point_groups,
                        'space_groups' => $space_groups,
                    )
                );
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Applies some CSS to a point group or space group value for Display mode.
     *
     * @param string $value
     *
     * @return string
     */
    private function applySymmetryCSS($value)
    {
        if ( strpos($value, '-') !== false )
            $value = preg_replace('/-(\d)/', '<span class="overbar">$1</span>', $value);
        if ( strpos($value, '_') !== false )
            $value = preg_replace('/_(\d)/', '<sub>$1</sub>', $value);

        return $value;
    }


    /**
     * Converts a non-empty string of a cellparameter value into a float.
     *
     * @param string $str
     * @return float
     */
    private function getBaseValue($str)
    {
        // Some of these values have tildes in them
        $str = str_replace('~', '', $str);

        // Extract the part of the string before the tolerance, if it exists
        $paren = strpos($str, '(');
        if ( $paren !== false )
            $str = substr($str, 0, $paren);

        // Convert the string into a float value
        return floatval($str);
    }


    /**
     * The references that this database is built from don't always specify the volume, but it's
     * quite useful to always have the volume.  Which means there needs to be a calculation method...
     *
     * @param string $a
     * @param string $b
     * @param string $c
     * @param string $alpha
     * @param string $beta
     * @param string $gamma
     * @return string
     */
    private function calculateVolume($a, $b, $c, $alpha, $beta, $gamma)
    {
        // Ensure that the given values don't have any tolerances on them
        $a = self::getBaseValue($a);
        $b = self::getBaseValue($b);
        $c = self::getBaseValue($c);
        $alpha = self::getBaseValue($alpha);
        $beta = self::getBaseValue($beta);
        $gamma = self::getBaseValue($gamma);

        // The angles need to be converted into radians first...
        $alpha = $alpha * M_PI / 180.0;
        $beta = $beta * M_PI / 180.0;
        $gamma = $gamma * M_PI / 180.0;

        // Calculate the volume using the generalized parallelpiped volume formula
        $cos_alpha = cos($alpha);
        $cos_beta = cos($beta);
        $cos_gamma = cos($gamma);
        $volume = $a * $b * $c * sqrt( 1.0-$cos_alpha*$cos_alpha-$cos_beta*$cos_beta-$cos_gamma*$cos_gamma + 2.0*$cos_alpha*$cos_beta*$cos_gamma );

        // Round it to three decimal places...technically incorrect, as the calculation really should
        //  take significant figures into account...
        return round($volume, 3);
    }


    /**
     * It's easier to find point groups in a dropdown if they're ordered by name...
     *
     * @return array
     */
    private function sortPointGroups()
    {
        // Probably an over-optimization, but...
        $tmp = $this->cache_service->get('cached_point_groups');
        if ( is_array($tmp) )
            return $tmp;

        // Convert the array of point groups into more of a lookup table...
        $tmp = array();
        foreach ($this->point_groups as $crystal_system => $pgs) {
            foreach ($pgs as $pg)
                $tmp[ strval($pg) ] = $crystal_system;
        }

        // Sort the point groups by key...
        uksort($tmp, function($a, $b) {
            // ...ignoring a leading hyphen character if it exists
            if ( is_numeric($a) )
                $a = strval($a);
            if ( is_numeric($b) )
                $b = strval($b);

            if ( $a[0] === '-' )
                $a = substr($a, 1);
            if ( $b[0] === '-' )
                $b = substr($b, 1);

            return strcmp($a, $b);
        });

        $this->cache_service->set('cached_point_groups', $tmp);
        return $tmp;
    }

    /**
     * It's considerably easier to find space groups in a dropdown if they're ordered by name...
     *
     * @return array
     */
    private function sortSpaceGroups()
    {
        // Probably an over-optimization, but...
        $tmp = $this->cache_service->get('cached_space_groups');
        if ( is_array($tmp) )
            return $tmp;

        // Convert the array of space groups into more of a lookup table...
        $tmp = array();
        foreach ($this->space_group_mapping as $point_group => $sg_num_list) {
            foreach ($sg_num_list as $sg_num) {
                foreach ($this->space_groups[$sg_num] as $space_group)
                    $tmp[$space_group] = str_replace('/', 's', $point_group);
            }
        }

        // ...then sort it by space group...don't need to worry about leading hypens
        ksort($tmp);

        $this->cache_service->set('cached_space_groups', $tmp);
        return $tmp;
    }


    /**
     * Due to needing to detect several types of problems with the values of the fields in this plugin,
     * it's easier to collect the relevant data separately.
     *
     * @param array $datatype
     * @param array $datarecord
     *
     * @return array
     */
    private function getRelevantFields($datatype, $datarecord)
    {
        $relevant_datafields = array(
            'Crystal System' => array(),
            'Point Group' => array(),
            'Space Group' => array(),
            'Lattice' => array(),
        );

        // Locate the relevant render plugin instance
        $rpm_entries = null;
        foreach ($datatype['renderPluginInstances'] as $rpi_num => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.cell_parameters' ) {
                $rpm_entries = $rpi['renderPluginMap'];
                break;
            }
        }

        // Determine the datafield ids of the relevant rpf entries
        foreach ($relevant_datafields as $rpf_name => $tmp) {
            // If any of the rpf entries are missing, that's a problem...
            if ( !isset($rpm_entries[$rpf_name]) )
                throw new ODRException('The renderPluginField "'.$rpf_name.'" is not mapped to the current database');

            // Otherwise, store the datafield id...
            $df_id = $rpm_entries[$rpf_name]['id'];
            $relevant_datafields[$rpf_name]['id'] = $df_id;

            // ...and locate the datafield's value from the datarecord array if it exists
            if ( !isset($datarecord['dataRecordFields'][$df_id]) ) {
                $relevant_datafields[$rpf_name]['value'] = '';
            }
            else {
                $drf = $datarecord['dataRecordFields'][$df_id];

                // Don't know the typeclass, so brute force it
                unset( $drf['id'] );
                unset( $drf['created'] );
                unset( $drf['file'] );
                unset( $drf['image'] );
                unset( $drf['dataField'] );

                // Should only be one entry left at this point
                foreach ($drf as $typeclass => $data) {
                    $relevant_datafields[$rpf_name]['typeclass'] = $typeclass;

                    if ( !isset($data[0]) || !isset($data[0]['value']) )
                        $relevant_datafields[$rpf_name]['value'] = '';
                    else
                        $relevant_datafields[$rpf_name]['value'] = $data[0]['value'];
                }
            }
        }

        return $relevant_datafields;
    }


    /**
     * Need to check for and warn if a derived field is blank when the source field is not.
     *
     * @param array $relevant_datafields @see self::getRelevantFields()
     *
     * @return array
     */
    private function findDerivationProblems($relevant_datafields)
    {
        // Only interested in the contents of datafields mapped to these rpf entries
        $derivations = array(
            'Space Group' => 'Lattice',
        );

        $problems = array();

        foreach ($derivations as $source_rpf => $dest_rpf) {
            if ( $relevant_datafields[$source_rpf]['value'] !== ''
                && $relevant_datafields[$dest_rpf]['value'] === ''
            ) {
                $dest_df_id = $relevant_datafields[$dest_rpf]['id'];
                $problems[$dest_df_id] = 'There seems to be a problem with the contents of the "'.$source_rpf.'" field.';
            }
        }

        // Return a list of any problems found
        return $problems;
    }


    /**
     * Handles when a datarecord is created.
     *
     * @param DatarecordCreatedEvent $event
     */
    public function onDatarecordCreate(DatarecordCreatedEvent $event)
    {
        // TODO - disabled for import testing, re-enable this later on
        return;

        // Pull some required data from the event
        $user = $event->getUser();
        $datarecord = $event->getDatarecord();
        $datatype = $datarecord->getDataType();

        // Need to locate the "mineral_id" field for this render plugin...
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:RenderPlugin rp
            JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
            JOIN ODRAdminBundle:RenderPluginMap rpm WITH rpm.renderPluginInstance = rpi
            JOIN ODRAdminBundle:DataFields df WITH rpm.dataField = df
            JOIN ODRAdminBundle:RenderPluginFields rpf WITH rpm.renderPluginFields = rpf
            WHERE rp.pluginClassName = :plugin_classname AND rpi.dataType = :datatype
            AND rpf.fieldName = :field_name
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
            AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'plugin_classname' => 'odr_plugins.rruff.cell_parameters',
                'datatype' => $datatype->getId(),
                'field_name' => 'Cell Parameter ID'
            )
        );
        $results = $query->getResult();
        if ( count($results) !== 1 )
            throw new ODRException('Unable to find the "Cell Parameter ID" field for the "RRUFF Cell Parameters" RenderPlugin, attached to Datatype '.$datatype->getId());

        // Will only be one result, at this point
        $datafield = $results[0];
        /** @var DataFields $datafield */


        // ----------------------------------------
        // Need to acquire a lock to ensure that there are no duplicate values
        $lockHandler = $this->lock_service->createLock('datatype_'.$datatype->getId().'_autogenerate_id'.'.lock', 15);    // 15 second ttl
        if ( !$lockHandler->acquire() ) {
            // Another process is in the mix...block until it finishes
            $lockHandler->acquire(true);
        }

        // Now that a lock is acquired, need to find the "most recent" value for the field that is
        //  getting incremented...
        $old_value = self::findCurrentValue($datafield->getId());

        // Since the "most recent" mineral id is already an integer, just add 1 to it
        $new_value = $old_value + 1;

        // Create a new storage entity with the new value
        $this->ec_service->createStorageEntity($user, $datarecord, $datafield, $new_value, false);    // guaranteed to not need a PostUpdate event
        $this->logger->debug('Setting df '.$datafield->getId().' "Cell Parameter ID" of new dr '.$datarecord->getId().' to "'.$new_value.'"...', array(self::class, 'onDatarecordCreate()'));

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Not going to mark the datarecord as updated, but still need to do some other cache
        //  maintenance because a datafield value got changed...

        // If the datafield that got changed was a datatype's sort datafield, delete its cached datarecord order
        $sort_datatypes = $datafield->getSortDatatypes();
        foreach ($sort_datatypes as $num => $dt)
            $this->dbi_service->resetDatatypeSortOrder($dt->getId());

        // Delete any cached search results involving this datafield
        $this->search_cache_service->onDatafieldModify($datafield);
    }


    /**
     * For this database, the cell_param_id needs to be autogenerated.
     *
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements.
     *
     * @param int $datafield_id
     *
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function findCurrentValue($datafield_id)
    {
        // Going to use native SQL...DQL can't use limit without using querybuilder...
        // NOTE - deleting a record doesn't delete the related storage entities, so deleted minerals
        //  are still considered in this query
        $query =
           'SELECT e.value
            FROM odr_integer_value e
            WHERE e.data_field_id = :datafield AND e.deletedAt IS NULL
            ORDER BY e.value DESC
            LIMIT 0,1';
        $params = array(
            'datafield' => $datafield_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one value in the result...
        $current_value = null;
        foreach ($results as $result)
            $current_value = intval( $result['value'] );

        // ...but if there's not for some reason, return zero as the "current".  onDatarecordCreate()
        //  will increment it so that the value one is what will actually get saved.
        // NOTE - this shouldn't happen for the existing IMA list
        if ( is_null($current_value) )
            $current_value = 0;

        return $current_value;
    }


    /**
     * Determines whether the user changed the fields on the left below...and if so, then updates
     * the corresponding field to the right:
     *  - "Space Group" => "Lattice"
     *
     * @param PostUpdateEvent $event
     *
     * @throws \Exception
     */
    public function onPostUpdate(PostUpdateEvent $event)
    {
        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $datarecord = null;
        $datafield = null;
        $datatype = null;
        $destination_entity = null;
        $user = null;

        try {
            // Get entities related to the file
            $source_entity = $event->getStorageEntity();
            $datarecord = $source_entity->getDataRecord();
            $datafield = $source_entity->getDataField();
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            // Only care about a change to specific fields of a datatype using the IMA render plugin...
            $rpf_name = self::isEventRelevant($datafield);
            if ( !is_null($rpf_name) ) {
                // ----------------------------------------
                // One of the relevant datafields got changed
                $source_value = $source_entity->getValue();
                if ($typeclass === 'DatetimeValue')
                    $source_value = $source_value->format('Y-m-d H:i:s');
                $this->logger->debug('Attempting to derive a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$rpf_name.'): "'.$source_value.'"...', array(self::class, 'onPostUpdate()'));

                // Store the renderpluginfield name that will be modified
                $dest_rpf_name = null;
                if ($rpf_name === 'Space Group')
                    $dest_rpf_name = 'Lattice';

                // Locate the destination entity for the relevant source datafield
                $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                // Derive the new value for the destination entity
                $derived_value = null;
                if ($rpf_name === 'Space Group')
                    $derived_value = substr($source_value, 0, 1);

                // ...which is saved in the storage entity for the datafield
                $this->emm_service->updateStorageEntity(
                    $user,
                    $destination_entity,
                    array('value' => $derived_value),
                    false,    // no sense trying to delay flush
                    false    // don't fire PostUpdate event...nothing depends on these fields
                );
                $this->logger->debug(' -- updating datafield '.$destination_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$destination_entity->getId().' with the value "'.$source_value.'"...', array(self::class, 'onPostUpdate()'));

                // This only works because the datafields getting updated aren't files/images or
                //  radio/tag fields
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onPostUpdate()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));

            if ( !is_null($destination_entity) ) {
                // If an error was thrown, attempt to ensure any derived fields are blank
                self::saveOnError($user, $destination_entity);
            }

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( !is_null($destination_entity) ) {
                $this->logger->debug('All changes saved', array(self::class, 'onPostUpdate()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));
                self::clearCacheEntries($datarecord, $user, $destination_entity);

                // Provide a reference to the entity that got changed
                $event->setDerivedEntity($destination_entity);
                // At the moment, this is effectively only available to the API callers, since the
                //  event kind of vanishes after getting called by the EntityMetaModifyService or
                //  the EntityCreationService...
            }
        }
    }


    /**
     * Determines whether the user changed the fields on the left below via MassEdit...and if so,
     * then updates the corresponding field to the right:
     *  - "Space Group" => "Lattice"
     *
     * @param PostMassEditEvent $event
     *
     * @throws \Exception
     */
    public function onPostMassEdit(PostMassEditEvent $event)
    {
        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $datarecord = null;
        $datafield = null;
        $datatype = null;
        $destination_entity = null;
        $user = null;

        try {
            // Get entities related to the file
            $drf = $event->getDataRecordFields();
            $datarecord = $drf->getDataRecord();
            $datafield = $drf->getDataField();
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $datatype = $datafield->getDataType();
            $user = $event->getUser();

            // Only care about a change to specific fields of a datatype using the IMA render plugin...
            $rpf_name = self::isEventRelevant($datafield);
            if ( !is_null($rpf_name) ) {
                // ----------------------------------------
                // One of the relevant datafields got changed
                $source_entity = null;
                if ( $typeclass === 'ShortVarchar' )
                    $source_entity = $drf->getShortVarchar();
                else if ( $typeclass === 'MediumVarchar' )
                    $source_entity = $drf->getMediumVarchar();
                else if ( $typeclass === 'LongVarchar' )
                    $source_entity = $drf->getLongVarchar();
                else if ( $typeclass === 'LongText' )
                    $source_entity = $drf->getLongText();
                else if ( $typeclass === 'IntegerValue' )
                    $source_entity = $drf->getIntegerValue();
                else if ( $typeclass === 'DecimalValue' )
                    $source_entity = $drf->getDecimalValue();
                else if ( $typeclass === 'DatetimeValue' )
                    $source_entity = $drf->getDatetimeValue();

                // Only continue when stuff isn't null
                if ( !is_null($source_entity) ) {
                    $source_value = $source_entity->getValue();
                    if ($typeclass === 'DatetimeValue')
                        $source_value = $source_value->format('Y-m-d H:i:s');
                    $this->logger->debug('Attempting to derive a value from dt '.$datatype->getId().', dr '.$datarecord->getId().', df '.$datafield->getId().' ('.$rpf_name.'): "'.$source_value.'"...', array(self::class, 'onPostMassEdit()'));

                    // Store the renderpluginfield name that will be modified
                    $dest_rpf_name = null;
                    if ($rpf_name === 'Space Group')
                        $dest_rpf_name = 'Lattice';

                    // Locate the destination entity for the relevant source datafield
                    $destination_entity = self::findDestinationEntity($user, $datatype, $datarecord, $dest_rpf_name);

                    // Derive the new value for the destination entity
                    $derived_value = null;
                    if ($rpf_name === 'Space Group')
                        $derived_value = substr($source_value, 0, 1);

                    // ...which is saved in the storage entity for the datafield
                    $this->emm_service->updateStorageEntity(
                        $user,
                        $destination_entity,
                        array('value' => $derived_value),
                        false,    // no sense trying to delay flush
                        false    // don't fire PostUpdate event...nothing depends on these fields
                    );
                    $this->logger->debug(' -- updating datafield '.$destination_entity->getDataField()->getId().' ('.$dest_rpf_name.'), '.$typeclass.' '.$destination_entity->getId().' with the value "'.$derived_value.'"...', array(self::class, 'onPostMassEdit()'));

                    // This only works because the datafields getting updated aren't files/images or
                    //  radio/tag fields
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onPostMassEdit()', 'user '.$user->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));

            if ( !is_null($destination_entity) ) {
                // If an error was thrown, attempt to ensure any derived fields are blank
                self::saveOnError($user, $destination_entity);
            }

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( !is_null($destination_entity) ) {
                $this->logger->debug('All changes saved', array(self::class, 'onPostMassEdit()', 'dt '.$datatype->getId(), 'dr '.$datarecord->getId(), 'df '.$datafield->getId()));
                self::clearCacheEntries($datarecord, $user, $destination_entity);
            }
        }
    }


    /**
     * Returns the given datafield's renderpluginfield name if it should respond to the onPostUpdate
     * or onPostMassEdit events, or null if it shouldn't.
     *
     * @param DataFields $datafield
     *
     * @return null|string
     */
    private function isEventRelevant($datafield)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $datatype = $datafield->getDataType();
        $dt_array = $this->dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        if ( !isset($dt_array[$datatype->getId()]['renderPluginInstances']) )
            return null;

        // Only interested in changes made to the datafields mapped to these rpf entries
        $relevant_datafields = array(
            'Space Group' => 'Lattice',
        );

        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_num => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.cell_parameters' ) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    if ( isset($relevant_datafields[$rpf_name]) && $rpf['id'] === $datafield->getId() ) {
                        // The datafield that triggered the event is one of the relevant fields
                        //  ...ensure the destination field exists while we're here
                        $dest_rpf_name = $relevant_datafields[$rpf_name];
                        if ( !isset($rpi['renderPluginMap'][$dest_rpf_name]) ) {
                            // The destination field doesn't exist for some reason
                            return null;
                        }
                        else {
                            // The destination field exists, so the rest of the plugin will work
                            return $rpf_name;
                        }
                    }
                }
            }
        }

        // ...otherwise, this is not a relevant field, or the fields aren't mapped for some reason
        return null;
    }


    /**
     * Returns the storage entity that the onPostUpdate or onPostMassEdit events will write to.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param DataRecord $datarecord
     * @param string $destination_rpf_name
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    private function findDestinationEntity($user, $datatype, $datarecord, $destination_rpf_name)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $dt_array = $this->dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
        foreach ($dt_array[$datatype->getId()]['renderPluginInstances'] as $rpi_num => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.cell_parameters' ) {
                $df_id = $rpi['renderPluginMap'][$destination_rpf_name]['id'];
                break;
            }
        }

        // Hydrate the destination datafield...it's guaranteed to exist
        /** @var DataFields $datafield */
        $datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->find($df_id);

        // Return the storage entity for this datarecord/datafield pair
        return $this->ec_service->createStorageEntity($user, $datarecord, $datafield);
    }


    /**
     * Returns an array of which datafields are derived from which source datafields, with everything
     * identified by datafield id.
     *
     * @param array $render_plugin_instance
     *
     * @return array
     */
    public function getDerivationMap($render_plugin_instance)
    {
        // Don't execute on instances of other render plugins
        if ( $render_plugin_instance['renderPlugin']['pluginClassName'] !== 'odr_plugins.rruff.cell_parameters' )
            return array();
        $render_plugin_map = $render_plugin_instance['renderPluginMap'];

        // This plugin has one derived field...
        //  - "Lattice" is derived from "Space Group"
        $lattice_df_id = $render_plugin_map['Lattice']['id'];
        $space_group_df_id = $render_plugin_map['Space Group']['id'];

        // Since a datafield could be derived from multiple datafields, the source datafields need
        //  to be in an array (even though that's not the case here)
        return array(
            $lattice_df_id => array($space_group_df_id),
        );
    }


    /**
     * If some sort of error/exception was thrown, then attempt to blank out all the fields derived
     * from the file being read...this won't stop the file from being encrypted, which will allow
     * the renderplugin to recognize and display that something is wrong with this file.
     *
     * @param ODRUser $user
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $destination_storage_entity
     */
    private function saveOnError($user, $destination_storage_entity)
    {
        $dr = $destination_storage_entity->getDataRecord();
        $df = $destination_storage_entity->getDataField();

        try {
            if ( !is_null($destination_storage_entity) ) {
                $this->emm_service->updateStorageEntity(
                    $user,
                    $destination_storage_entity,
                    array('value' => ''),
                    false,    // no point delaying flush
                    false    // don't fire PostUpdate event...nothing depends on these fields
                );
                $this->logger->debug('-- -- updating dr '.$dr->getId().', df '.$df->getId().' to have the value ""...', array(self::class, 'saveOnError()'));
            }
        }
        catch (\Exception $e) {
            // Some other error...no way to recover from it
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'saveOnError()', 'user '.$user->getId(), 'dr '.$dr->getId(), 'df '.$df->getId()));
        }
    }


    /**
     * Wipes or updates relevant cache entries once everything is completed.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $destination_storage_entity
     */
    private function clearCacheEntries($datarecord, $user, $destination_storage_entity)
    {
        // The datarecord needs to be marked as updated
        $this->dri_service->updateDatarecordCacheEntry($datarecord, $user);

        // Because other datafields got updated, several more cache entries need to be wiped
        $this->search_cache_service->onDatafieldModify($destination_storage_entity->getDataField());
        $this->search_cache_service->onDatarecordModify($datarecord);
    }


    /**
     * The Lattice field for this plugin needs to use a different template when it's reloaded in
     * edit mode.
     *
     * @param string $rendering_context
     * @param RenderPluginInstance $render_plugin_instance
     * @param DataFields $datafield
     * @param DataRecord $datarecord
     * @param Theme $theme
     * @param ODRUser $user
     *
     * @return array
     */
    public function getOverrideParameters($rendering_context, $render_plugin_instance, $datafield, $datarecord, $theme, $user)
    {
        // Only override when called from the 'edit' context...the 'display' context might be a
        //  possibility in the future, but this plugin doesn't need to override there
        if ( $rendering_context !== 'edit' )
            return array();

        // Sanity checks
        if ( $render_plugin_instance->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.rruff.cell_parameters' )
            return array();
        $datatype = $datafield->getDataType();
        if ( $datatype->getId() !== $datarecord->getDataType()->getId() )
            return array();
        if ( $render_plugin_instance->getDataType()->getId() !== $datatype->getId() )
            return array();


        // Want the derived fields in IMA to complain if they're blank, but their source field isn't
        $dt_array = $this->dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
        $dr_array = $this->dri_service->getDatarecordArray($datarecord->getGrandparent()->getId(), false);

        // Locate any problems with the values
        $relevant_fields = self::getRelevantFields($dt_array[$datatype->getId()], $dr_array[$datarecord->getId()]);

        $relevant_rpf = null;
        foreach ($relevant_fields as $rpf_name => $data) {
            if ( $data['id'] === $datafield->getId() ) {
                $relevant_rpf = $rpf_name;
                break;
            }
        }

        // Only need to check for derivation/uniqueness problems when reloading in edit mode
        if ( $rendering_context === 'edit' ) {
            // Can't have uniqueness problems if there are derivation problems...
            $derivation_problems = self::findDerivationProblems($relevant_fields);
            if ( isset($derivation_problems[$datafield->getId()]) ) {
                // The derived field does not have a value, but the source field does...render the
                //  plugin's template instead of the default
                return array(
                    'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                    'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_edit_datafield_reload.html.twig',
                    'problem_fields' => $derivation_problems,
                );
            }
        }


        if ( $relevant_rpf === 'Crystal System'
            || $relevant_rpf === 'Point Group'
            || $relevant_rpf === 'Space Group'
        ) {
            // All reloads of these fields need to be overridden, to show the popup trigger button

            // Going to need several field identifiers, so all the symmetry fields can be saved
            //  at the same time via the popup
            $field_identifiers = array(
                'Crystal System' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$relevant_fields['Crystal System']['id'],
                'Point Group' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$relevant_fields['Point Group']['id'],
                'Space Group' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$relevant_fields['Space Group']['id'],
                'Lattice' => 'ShortVarcharForm_'.$datarecord->getId().'_'.$relevant_fields['Lattice']['id'],
            );

            // Tweak the point group and space group arrays so that they're in order...they're
            //  not defined in order, because it's considerably easier to fix any problems with
            //  them when they're arranged by category instead of by name
            $point_groups = self::sortPointGroups();
            $space_groups = self::sortSpaceGroups();

            // Also going to need a token for the custom form submission...
            $token_id = 'RRUFFCellParams_'.$datafield->getDataType()->getId().'_'.$datarecord->getId();
            $token_id .= '_'.$relevant_fields['Crystal System']['id'];
            $token_id .= '_'.$relevant_fields['Point Group']['id'];
            $token_id .= '_'.$relevant_fields['Space Group']['id'];
            $token_id .= '_Form';
            $form_token = $this->token_manager->getToken($token_id)->getValue();

            return array(
                'token_list' => array(),    // so ODRRenderService generates CSRF tokens
                'template_name' => 'ODROpenRepositoryGraphBundle:RRUFF:CellParams/cellparams_edit_datafield.html.twig',

                'field_identifiers' => $field_identifiers,
                'form_token' => $form_token,

                'crystal_systems' => $this->crystal_systems,
                'point_groups' => $point_groups,
                'space_groups' => $space_groups,
            );
        }

        // Otherwise, don't want to override the default reloading for this field
        return array();
    }


    /**
     * Returns an array of datafields where MassEdit should enable the abiilty to run a background
     * job without actually changing their values.
     *
     * @param array $render_plugin_instance
     * @return array An array where the keys are datafield ids, and the values aren't used
     */
    public function getMassEditOverrideFields($render_plugin_instance)
    {
        if ( !isset($render_plugin_instance['renderPluginMap']) )
            throw new ODRException('Invalid plugin config');

        // Only interested in overriding datafields mapped to these rpf entries
        $relevant_datafields = array(
            'Space Group' => 'Lattice',
        );

        $ret = array(
            'label' => $render_plugin_instance['renderPlugin']['pluginName'],
            'fields' => array()
        );

        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            if ( isset($relevant_datafields[$rpf_name]) )
                $ret['fields'][ $rpf['id'] ] = 1;
        }

        return $ret;
    }


    /**
     * Returns an array of datafield values that TableThemeHelperService should display, instead of
     * using the values in the datarecord.
     *
     * @param array $render_plugin_instance
     * @param array $datarecord
     * @param array|null $datafield
     *
     * @return string[] An array where the keys are datafield ids, and the values are the strings to display
     */
    public function getTableResultsOverrideValues($render_plugin_instance, $datarecord, $datafield = null)
    {
        // This render plugin might need to modify three different fields...
        $values = array();

        // ...it's easier if all relevant values are found first
        $current_values = array();
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            switch ($rpf_name) {
                case 'Point Group':
                case 'Space Group':
                case 'a':
                case 'b':
                case 'c':
                case 'alpha':
                case 'beta':
                case 'gamma':
                case 'Volume':
                    // This is a field of interest...
                    $df_id = $rpf['id'];
                    $current_values[$rpf_name] = array(
                        'id' => $df_id,
                        'value' => ''
                    );

                    // Need to look through the datarecord to find the current value...all of these
                    //  fields are guaranteed to be ShortVarchar fields
                    if ( isset($datarecord['dataRecordFields'][$df_id]['shortVarchar'][0]['value']) )
                        $current_values[$rpf_name]['value'] = $datarecord['dataRecordFields'][$df_id]['shortVarchar'][0]['value'];
                    break;
            }
        }

        // The Point Group and the Space Group need to have some CSS applied to them
        if ( $current_values['Point Group']['value'] !== '' ) {
            $point_group_df_id = $current_values['Point Group']['id'];
            $values[$point_group_df_id] = self::applySymmetryCSS( $current_values['Point Group']['value'] );
        }
        if ( $current_values['Space Group']['value'] !== '' ) {
            $space_group_df_id = $current_values['Space Group']['id'];
            $values[$space_group_df_id] = self::applySymmetryCSS( $current_values['Space Group']['value'] );
        }

        // The Volume needs to be calculated if it does not already exist...
        if ( $current_values['Volume']['value'] === '' ) {
            $volume_df_id = $current_values['Volume']['id'];

            // ...but only if the six cellparameter values exist
            $a = $current_values['a']['value'];
            $b = $current_values['b']['value'];
            $c = $current_values['c']['value'];
            $alpha = $current_values['alpha']['value'];
            $beta = $current_values['beta']['value'];
            $gamma = $current_values['gamma']['value'];

            if ( $a !== '' && $b !== '' && $c !== '' && $alpha !== '' && $beta !== '' && $gamma !== '')
                $values[$volume_df_id] = self::calculateVolume($a, $b, $c, $alpha, $beta, $gamma);
        }

        // Return all modified values
        return $values;
    }
}
