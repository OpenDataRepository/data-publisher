<?php

/**
 * Open Data Repository Data Publisher
 * Crystallography Groups Definition
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Since the crystallography stuff might be useful in more than one place, it's better to have them
 * off in their own file...
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Exceptions
use ODR\AdminBundle\Exception\ODRException;

class CrystallographyDef
{

    /**
     * Defines the 8 official crystal systems...
     *
     * @var string[]
     */
    public static $crystal_systems = array(
        'cubic',
        'hexagonal',
//        'rhombohedral',    // Only really used in Europe, see below
//        'trigonal',
        'tetragonal',
        'orthorhombic',
        'monoclinic',
        'triclinic',
        'amorphous',
        'unknown',
    );


    /**
     * Defines the 32 official crystallographic point groups in terms of which crystal system they
     * belong to.  The names of the point groups come from the American Mineralogy Crystal Structure
     * Database (AMCSD) and the RRUFF Project.
     *
     * @var array
     */
    public static $point_groups = array(
        'cubic' => array('-3-32/m', '332', '4/m-32/m', '432', '-43m'),

        // Europe has/had a habit of using 'hexagonal' to refer to point groups starting with 6, and
        //  'rhomobohedral' or 'trigonal' for those starting with 3...since this is based in the
        //  US, we don't really care about the distinction and use 'hexagonal' for those starting
        //  with both 3 and 6
        'hexagonal' => array('3', '-3', '-32/m2/m', '322', '3mm', '6', '-6', '6/m', '6/m2/m2/m', '622', '-62m', '6mm'),
//        'rhombohedral' => array('3', '-3', '322', '-32/m2/m', '3mm'),
//        'trigonal' => array('6', '-6', '6/m', '6/m2/m2/m', '622', '-62m', '6mm'),

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
    public static $space_group_mapping = array(
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
        // hexagonal (this subset is also known as trigonal/rhomobohedral outside the US)
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
     * (some of) the acceptable labels for said space groups in Wyckoff notation..."P1" and "A1"
     * both mean space group #1, for instance.
     *
     * The synonyms exist because which label actually gets used depends on which axes/angles get
     * assigned to the a/b/c/α/β/γ values.  It's a convenience feature, mostly.
     *
     * @var array[]
     */
    public static $space_groups = array(
        1 => array('P1', 'A1', 'B1', 'C1', 'I1', 'F1'),
        2 => array('P-1', 'A-1', 'B-1', 'C-1', 'I-1', 'F-1'),
        3 => array('P2'),
        4 => array('P2_1'),
        5 => array('A2', 'A2_1', 'B2', 'B2_1', 'C2', 'C2_1', 'F2', 'I2'),
        6 => array('Pm'),
        7 => array('Pa', 'Pb', 'Pc', 'Pn'),
        8 => array('Am', 'Ab', 'Ac', 'Bm', 'Ba', 'Bc', 'Cm', 'Ca', 'Cb', 'Im'),
        9 => array('Aa', 'An', 'Bb', 'Bn', 'Cc', 'Cn', 'Fd', 'Ia', 'Ib'),
        10 => array('P2/m'),
        11 => array('B2_1/m', 'P2_1/m'),
        12 => array('A2/m', 'A2_1/b', 'A2_1/c', 'B2/m', 'B2_1/a', 'B2_1/c', 'C2/m', 'C2_1/a', 'C2_1/b', 'F2/m', 'I2/m'),
        13 => array('C2/a', 'P2/a', 'P2/b', 'P2/c', 'P2/n'),
        14 => array('B2_1/d', 'C2_1/d', 'P2_1/a', 'P2_1/b', 'P2_1/c', 'P2_1/n'),
        15 => array('A2/a', 'A2/n', 'A2_1/n', 'B2/b', 'B2/n', 'B2_1/n', 'C2/c', 'C2_1/n', 'F2/d', 'I2/a', 'I2/b', 'I2/c'),
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
        37 => array('A2aa', 'A2nn', 'Bb2b', 'Bn2n', 'Ccc2', 'Cnn2'),
        38 => array('Amm2', 'Am2m', 'Anc2_1', 'An2_1b', 'B2mm', 'Bmm2', 'B2_1na', 'Bcn2_1', 'C2mm', 'Cm2m', 'Cb2_1n', 'C2_1an'),
        39 => array('Abm2', 'Ac2m', 'Acc2_1', 'Ab2_1b', 'B2cm', 'Bma2', 'B2_1aa', 'Bcc2_1', 'Cm2a', 'C2mb', 'Cb2_1b', 'C2_1aa'),
        40 => array('Ama2', 'Am2a', 'Ann2_1', 'An2_1n', 'B2mb', 'Bbm2', 'B2_1nn', 'Bnn2_1', 'Cc2m', 'C2cm', 'Cn2_1n', 'C2_1nn'),
        41 => array('Aba2', 'Ac2a', 'Acn2_1', 'Ab2_1n', 'B2cb', 'Bba2', 'B2_1an', 'Bnc2_1', 'Cc2a', 'C2cb', 'Cn2_1b', 'C2_1na'),
        42 => array('F2mm', 'Fmm2', 'Fbc2_1', 'Fca2_1', 'Fnn2', 'Fm2m', 'Fb2_1a', 'Fc2_1b', 'Fn2n'),
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
        72 => array('Ibam', 'Iccn', 'Imab', 'Imcb', 'Inaa', 'Icma', 'Ibnb'),
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
        117 => array('P-4b2', 'P-4bc', 'C-42b'),
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
        221 => array('Pm3m', 'Pm-3m', 'P4/m-32/m'),
        222 => array('Pn3n', 'P4/n-32/n'),
        223 => array('Pm3n', 'P4_1/m-32/n'),
        224 => array('Pn3m', 'P4_1/n-32/m'),
        225 => array('Fm3m', 'Fm-3m', 'F4/m-32/m'),
        226 => array('Fm3c', 'F4/m-32/c'),
        227 => array('Fd3m', 'Fd-3m', 'F4_1/d-32/m'),
        228 => array('Fd3c', 'F4_1/d-32/c'),
        229 => array('Im3m', 'I4/m-32/m'),
        230 => array('Ia3d', 'I4_1/a-32/d', 'Ia-3d'),
    );


    /**
     * This is less of a derivation and more of a search, but whatever.
     *
     * @var string $point_group
     * @return string
     */
    public static function deriveCrystalSystemFromPointGroup($point_group)
    {
        // Should be able to find the point group in this array
        foreach (self::$point_groups as $crystal_system => $pg_list) {
            if ( in_array($point_group, $pg_list) )
                return $crystal_system;
        }

        // If not, return an empty string
        return '';
    }


    /**
     * The space groups can (almost) always be directly derived back into their point group...the
     * algorithm for doing so isn't difficult.
     *
     * @var string $space_group
     * @return string
     */
    public static function derivePointGroupFromSpaceGroup($space_group)
    {
        // The lattice is always the first character, and should be dropped...
        $pg = substr($space_group, 1);
        // ...Replace all remaining letters with 'm'
        $pg = str_replace(array('a','b','c','d','n'), 'm', $pg);
        // ...eliminate subscripts
        $pg = preg_replace('/_\d/', '', $pg);

        // If the derivation is invalid, then look up the correct point group
        if ( isset(self::$point_group_synonyms[$pg]) )
            $pg = self::$point_group_synonyms[$pg];

        return $pg;
    }


    /**
     * There are a couple space groups that self::derivePointGroupFromSpaceGroup() doesn't quite
     * arrive at the "correct" answer that AMCSD/RRUFF/ODR expect...this array maps those "errors"
     * to the expected ones.
     *
     * @var string[]
     */
    public static $point_group_synonyms = array(
        // triclinic

        // monoclinic

        // orthorhombic
        'm2m' => '2mm',
        'mm2' => '2mm',
        'mmm' => '2/m2/m2/m',

        // tetragonal
        '-4m2' => '-42m',
        '4/mmm' => '4/m2/m2/m',

        // hexagonal
        '32' => '322',
        '321' => '322',
        '312' => '322',
        '3m1' => '3mm',
        '31m' => '3mm',
        '3m' => '3mm',
        '-31m' => '-32/m2/m',
        '-312/m' => '-32/m2/m',
        '-3m1' => '-32/m2/m',
        '-32/m1' => '-32/m2/m',
        '-3m' => '-32/m2/m',
        '-32/m' => '-32/m2/m',

        '-6m2' => '-62m',
        '6/mmm' => '6/m2/m2/m',

        // cubic
        '23' => '332',
        'm3' => '-3-32/m',
        '2/m-3' => '-3-32/m',
        'm-3m' => '4/m-32/m',
        'm3m' => '4/m-32/m',
    );


    /**
     * Since the volume calculation formula is used in multiple places, it might as well be in the
     * definitions file as well.
     *
     * @param float $a
     * @param float $b
     * @param float $c
     * @param float $alpha All angles must be in radians
     * @param float $beta
     * @param float $gamma
     * @return float
     */
    public static function calculateVolume($a, $b, $c, $alpha, $beta, $gamma)
    {
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
     * There are several different notations to indicate space groups, but the American Mineralogy
     * Crystal Structure Database (AMCSD), the RRUFF Project...and by extension, ODR...use Wyckoff
     * notation.
     *
     * This particular function converts the Hermann–Mauguin notation into the Wyckoff notation. It
     * was created by experimenting on the 21k+ CIF files in AMCSD as of May 2025.
     *
     * @param string $hm_space_group
     * @return string
     */
    public static function convertHermannMauguinToWyckoffSpaceGroup($hm_space_group)
    {
        // The Hermann–Mauguin space group notation has 2-4 pieces...lattice is the first one, then
        //  it's followed by up to three other elements.  Those other elements are just '1' in a fair
        //  number of cases, which the Wyckoff notation doesn't bother to display...presumably
        //  because they don't affect the symmetry.  Don't quote me on that, I lack the background.
        $num_spaces = 0;
        for ($i = 0; $i < strlen($hm_space_group); $i++) {
            if ( $hm_space_group[$i] === ' ' )
                $num_spaces++;
        }

        $wyckoff_space_group = $hm_space_group;
        if ( $num_spaces > 1 && strpos($hm_space_group, 'P 3') === false && strpos($hm_space_group, 'P -3') === false) {
            // These '1's aren't "extra" if there's only two elements total, or if it describes a
            //  hexagonal crystal system...everywhere else they should be removed
            $wyckoff_space_group = str_replace(' 1', '', $wyckoff_space_group);
        }

        // The Wyckoff notation also tends to use subscripts for certain symmetry operations, which
        //  the Hermann–Mauguin notation does not
        $wyckoff_space_group = str_replace(
            array(' :H', ' :'),
            '',
            $wyckoff_space_group
        );
        $wyckoff_space_group = str_replace(
            array('21', '31', '32', '41', '42', '43', '61', '62', '63', '64', '65'),
            array('2_1', '3_1', '3_2', '4_1', '4_2', '4_3', '6_1', '6_2', '6_3', '6_4', '6_5'),
            $wyckoff_space_group
        );

        // The remaining spaces can now get removed to finish the conversion...
        $wyckoff_space_group = str_replace(' ', '', $wyckoff_space_group);
        // ...except the triclinc space group #1 has typically been mangled by this point and needs
        //  to have a '1' added back in
        if ( strlen($wyckoff_space_group) === 1 )
            $wyckoff_space_group = $wyckoff_space_group.'1';

        return $wyckoff_space_group;
    }


    /**
     * CIF files allow a dizzying array of data in them...a lot of it has to do with diffraction
     * patterns, but the AMCSD database on ODR would prefer CIFs to just have structure data.
     *
     * The following strings are prefixes to keys that AMCSD doesn't want to deal with.
     *
     * @var array
     */
    public static $cif_key_blacklist = array(
        '_exptl_' => 0,
        '_diffrn_' => 0,
        '_refln_' => 0,
        '_reflns_' => 0,
        '_computing_' => 0,
        '_refine_' => 0,
        '_olex2_' => 0,
//        '_atom_' => 0,
        '_geom_' => 0,
        '_shelx_' => 0,
        '_oxdiff_' => 0,
        '_jana_' => 0,
//        '_pd_' => 0,
        '_pd_calc_' => 0,
        '_twin_' => 0,
    );


    /**
     * There are several keys that match {@link self::$cif_key_blacklist}, but are actually useful
     * ...so there also needs to be a whitelist to override the blacklist.
     *
     * @var array
     */
    public static $cif_key_whitelist = array(
        '_shelx_SHELXL_version_number' => 1,
        '_exptl_crystal_density_diffrn' => 1,
//        '_atom_type_symbol' => 1,
//        '_atom_site_label' => 1,
//        '_atom_site_aniso_label' => 1,
    );


    /**
     * A list of the Ka1/Ka2 wavelengths for each element, with Kavg = (2*Ka1 + Ka2)/3
     * @var array
     */
    public static $element_radiation_wavelengths = array(
        'Li' => array('Ka1' => 228, 'Ka2' => 228, 'Kavg' => 228),
        'Be' => array('Ka1' => 114, 'Ka2' => 114, 'Kavg' => 114),
        'B' => array('Ka1' => 67.6, 'Ka2' => 67.6, 'Kavg' => 67.6),
        'C' => array('Ka1' => 44.7, 'Ka2' => 44.7, 'Kavg' => 44.7),
        'N' => array('Ka1' => 31.6, 'Ka2' => 31.6, 'Kavg' => 31.6),
        'O' => array('Ka1' => 23.62, 'Ka2' => 23.62, 'Kavg' => 23.62),
        'F' => array('Ka1' => 18.32, 'Ka2' => 18.32, 'Kavg' => 18.32),
        'Ne' => array('Ka1' => 14.61, 'Ka2' => 14.61, 'Kavg' => 14.61),
        'Na' => array('Ka1' => 11.9101, 'Ka2' => 11.9101, 'Kavg' => 11.9101),
        'Mg' => array('Ka1' => 9.89, 'Ka2' => 9.89, 'Kavg' => 9.89),
        'Al' => array('Ka1' => 8.33934, 'Ka2' => 8.34173, 'Kavg' => 8.340136),
        'Si' => array('Ka1' => 7.12542, 'Ka2' => 7.12791, 'Kavg' => 7.12625),
        'P' => array('Ka1' => 6.157, 'Ka2' => 6.16, 'Kavg' => 6.158),
        'S' => array('Ka1' => 5.37216, 'Ka2' => 5.37496, 'Kavg' => 5.373093),
        'Cl' => array('Ka1' => 4.7278, 'Ka2' => 4.7307, 'Kavg' => 4.728766),
        'Ar' => array('Ka1' => 4.1918, 'Ka2' => 4.19474, 'Kavg' => 4.19278),
        'K' => array('Ka1' => 3.7414, 'Ka2' => 3.7445, 'Kavg' => 3.742433),
        'Ca' => array('Ka1' => 3.35839, 'Ka2' => 3.36166, 'Kavg' => 3.35948),
        'Sc' => array('Ka1' => 3.0309, 'Ka2' => 3.0342, 'Kavg' => 3.032),
        'Ti' => array('Ka1' => 2.74851, 'Ka2' => 2.75216, 'Kavg' => 2.749726),
        'V' => array('Ka1' => 2.50356, 'Ka2' => 2.50738, 'Kavg' => 2.504833),
        'Cr' => array('Ka1' => 2.2897, 'Ka2' => 2.293606, 'Kavg' => 2.291002),
        'Mn' => array('Ka1' => 2.10182, 'Ka2' => 2.10578, 'Kavg' => 2.10314),
        'Fe' => array('Ka1' => 1.936042, 'Ka2' => 1.93998, 'Kavg' => 1.937354),
        'Co' => array('Ka1' => 1.788965, 'Ka2' => 1.79285, 'Kavg' => 1.79026),
        'Ni' => array('Ka1' => 1.65791, 'Ka2' => 1.661747, 'Kavg' => 1.659189),
        'Cu' => array('Ka1' => 1.540562, 'Ka2' => 1.54439, 'Kavg' => 1.541838),
        'Zn' => array('Ka1' => 1.435155, 'Ka2' => 1.439, 'Kavg' => 1.436436),
        'Ga' => array('Ka1' => 1.340083, 'Ka2' => 1.34399, 'Kavg' => 1.341385),
        'Ge' => array('Ka1' => 1.254054, 'Ka2' => 1.258011, 'Kavg' => 1.255373),
        'As' => array('Ka1' => 1.17588, 'Ka2' => 1.17987, 'Kavg' => 1.17721),
        'Se' => array('Ka1' => 1.10477, 'Ka2' => 1.10882, 'Kavg' => 1.10612),
        'Br' => array('Ka1' => 1.03974, 'Ka2' => 1.04382, 'Kavg' => 1.0411),
        'Kr' => array('Ka1' => 0.9801, 'Ka2' => 0.9841, 'Kavg' => 0.981433),
        'Rb' => array('Ka1' => 0.925553, 'Ka2' => 0.92969, 'Kavg' => 0.926932),
        'Sr' => array('Ka1' => 0.87526, 'Ka2' => 0.87943, 'Kavg' => 0.87665),
        'Y' => array('Ka1' => 0.82884, 'Ka2' => 0.83305, 'Kavg' => 0.830243),
        'Zr' => array('Ka1' => 0.78593, 'Ka2' => 0.79015, 'Kavg' => 0.787336),
        'Nb' => array('Ka1' => 0.7462, 'Ka2' => 0.75044, 'Kavg' => 0.747613),
        'Mo' => array('Ka1' => 0.7093, 'Ka2' => 0.71359, 'Kavg' => 0.71073),
        'Tc' => array('Ka1' => 0.67502, 'Ka2' => 0.67932, 'Kavg' => 0.676453),
        'Ru' => array('Ka1' => 0.643083, 'Ka2' => 0.647408, 'Kavg' => 0.644524),
        'Rh' => array('Ka1' => 0.613279, 'Ka2' => 0.61763, 'Kavg' => 0.614729),
        'Pd' => array('Ka1' => 0.585448, 'Ka2' => 0.589821, 'Kavg' => 0.586905),
        'Ag' => array('Ka1' => 0.5594075, 'Ka2' => 0.563798, 'Kavg' => 0.560871),
        'Cd' => array('Ka1' => 0.53501, 'Ka2' => 0.539422, 'Kavg' => 0.53648),
        'In' => array('Ka1' => 0.512113, 'Ka2' => 0.516544, 'Kavg' => 0.51359),
        'Sn' => array('Ka1' => 0.490599, 'Ka2' => 0.495053, 'Kavg' => 0.492083),
        'Sb' => array('Ka1' => 0.470354, 'Ka2' => 0.474827, 'Kavg' => 0.471845),
        'Te' => array('Ka1' => 0.451295, 'Ka2' => 0.455784, 'Kavg' => 0.452791),
        'I' => array('Ka1' => 0.433318, 'Ka2' => 0.437829, 'Kavg' => 0.434821),
        'Xe' => array('Ka1' => 0.41634, 'Ka2' => 0.42087, 'Kavg' => 0.41785),
        'Cs' => array('Ka1' => 0.40029, 'Ka2' => 0.404835, 'Kavg' => 0.401805),
        'Ba' => array('Ka1' => 0.385111, 'Ka2' => 0.389668, 'Kavg' => 0.38663),
        'La' => array('Ka1' => 0.370737, 'Ka2' => 0.375313, 'Kavg' => 0.372262),
        'Ce' => array('Ka1' => 0.357092, 'Ka2' => 0.361683, 'Kavg' => 0.358622),
        'Pr' => array('Ka1' => 0.34414, 'Ka2' => 0.348749, 'Kavg' => 0.345676),
        'Nd' => array('Ka1' => 0.331846, 'Ka2' => 0.336472, 'Kavg' => 0.333388),
        'Pm' => array('Ka1' => 0.32016, 'Ka2' => 0.324803, 'Kavg' => 0.321707),
        'Sm' => array('Ka1' => 0.30904, 'Ka2' => 0.313698, 'Kavg' => 0.310592),
        'Eu' => array('Ka1' => 0.298446, 'Ka2' => 0.303118, 'Kavg' => 0.300003),
        'Gd' => array('Ka1' => 0.288353, 'Ka2' => 0.293038, 'Kavg' => 0.289914),
        'Tb' => array('Ka1' => 0.278724, 'Ka2' => 0.283423, 'Kavg' => 0.28029),
        'Dy' => array('Ka1' => 0.269533, 'Ka2' => 0.274247, 'Kavg' => 0.271104),
        'Ho' => array('Ka1' => 0.260756, 'Ka2' => 0.265486, 'Kavg' => 0.262332),
        'Er' => array('Ka1' => 0.252365, 'Ka2' => 0.25711, 'Kavg' => 0.253946),
        'Tm' => array('Ka1' => 0.244338, 'Ka2' => 0.249095, 'Kavg' => 0.245923),
        'Yb' => array('Ka1' => 0.236655, 'Ka2' => 0.241424, 'Kavg' => 0.238244),
        'Lu' => array('Ka1' => 0.229298, 'Ka2' => 0.234081, 'Kavg' => 0.230892),
        'Hf' => array('Ka1' => 0.222227, 'Ka2' => 0.227024, 'Kavg' => 0.223826),
        'Ta' => array('Ka1' => 0.215497, 'Ka2' => 0.220305, 'Kavg' => 0.217099),
        'W' => array('Ka1' => 0.20901, 'Ka2' => 0.213828, 'Kavg' => 0.210616),
        'Re' => array('Ka1' => 0.202781, 'Ka2' => 0.207611, 'Kavg' => 0.204391),
        'Os' => array('Ka1' => 0.196794, 'Ka2' => 0.201639, 'Kavg' => 0.198409),
        'Ir' => array('Ka1' => 0.191047, 'Ka2' => 0.195904, 'Kavg' => 0.192666),
        'Pt' => array('Ka1' => 0.185511, 'Ka2' => 0.190381, 'Kavg' => 0.187134),
        'Au' => array('Ka1' => 0.180195, 'Ka2' => 0.185075, 'Kavg' => 0.181821),
        'Hg' => array('Ka1' => 0.175068, 'Ka2' => 0.179958, 'Kavg' => 0.176698),
        'Tl' => array('Ka1' => 0.170136, 'Ka2' => 0.175036, 'Kavg' => 0.171769),
        'Pb' => array('Ka1' => 0.165376, 'Ka2' => 0.170294, 'Kavg' => 0.167015),
        'Bi' => array('Ka1' => 0.160789, 'Ka2' => 0.165717, 'Kavg' => 0.162431),
        'Po' => array('Ka1' => 0.15636, 'Ka2' => 0.1613, 'Kavg' => 0.158006),
        'At' => array('Ka1' => 0.1521, 'Ka2' => 0.15705, 'Kavg' => 0.15375),
        'Rn' => array('Ka1' => 0.14798, 'Ka2' => 0.15294, 'Kavg' => 0.149633),
        'Fr' => array('Ka1' => 0.14399, 'Ka2' => 0.14896, 'Kavg' => 0.145646),
        'Ra' => array('Ka1' => 0.14014, 'Ka2' => 0.14512, 'Kavg' => 0.1418),
        'Ac' => array('Ka1' => 0.136417, 'Ka2' => 0.14141, 'Kavg' => 0.138081),
        'Th' => array('Ka1' => 0.132813, 'Ka2' => 0.137829, 'Kavg' => 0.134485),
        'Pa' => array('Ka1' => 0.129352, 'Ka2' => 0.134343, 'Kavg' => 0.131015),
        'U' => array('Ka1' => 0.125947, 'Ka2' => 0.130968, 'Kavg' => 0.12762),
    );


    /**
     * Attempts to read a CIF file.
     *
     * The theory is to break the file apart into "nodes", which are one of the following:
     * 1) comment block
     * 2) key/value pair
     * 3) table-ish structure
     * The original text making up the node is also stored, which means the return also has blank
     * nodes for lines without data.
     *
     * By default, this function ends up checking syntax and returning data from the entire file. If
     * $apply_blacklist is set to true, then it will generally skip over anything blacklisted, and
     * only return data from the allowed keys/loops.
     *
     * The function will also attempt to validate loop structures by default, but since loops are
     * the most complicated part of the CIF definition and I keep having to fix the parser for them,
     * there's another option to just skip attempting to parse them.  If this used, then the resulting
     * loop nodes will have data for 'keys' and 'text', but will not have data for 'values'.
     *
     * @param string $file_contents
     * @param bool $apply_blacklist
     * @param bool $skip_loop_processing
     * @return array
     */
    public static function readCIFFile($file_contents, $apply_blacklist = false, $skip_loop_processing = false)
    {
        $structure = array();

        // Ensure carriage returns aren't in the file to make my life easier...
        $file_contents = str_replace("\r", '', $file_contents);
        $lines = explode("\n", $file_contents);

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            if ( strpos($line, '_') === 0 ) {
                // should be a regular data line
                $space = strpos($line, ' ');

                if ( $space !== false ) {
                    // The value is on the same line as the key
                    $key = substr($line, 0, $space);
                    $value = trim( substr($line, $space + 1) );
                    if ( $value !== "''" )
                        $value = self::stripQuotes( $value );

                    if ( !$apply_blacklist || !self::filterCIFEntity($key) ) {
                        $structure[] = array(
                            'key' => $key,
                            'value' => $value,
                            'text' => $lines[$i]."\r\n",
                        );
                    }
                }
                else {
                    // If the value is split across multiple lines, then the current line is the key
                    $key = $line;

                    // Need to store slightly different versions for the value and the text
                    $inside_semicolon = false;
                    $multiline_value = '';
                    $multiline_text = $lines[$i]."\r\n";
                    for ($j = $i+1; $j < count($lines); $j++) {
                        $multiline_value .= $lines[$j]."\r\n";
                        $multiline_text .= $lines[$j]."\r\n";
                        $loop_line = trim($lines[$j]);

                        if ( strpos($loop_line, ';') === 0 ) {
                            // ...semicolons are the legitimate method to indicate multiple lines
                            if ( !$inside_semicolon ) {
                                // Don't do anything special for the first semicolon...
                                $inside_semicolon = true;
                            }
                            else {
                                // ...but when the second one is encountered, finish up

                                // first get rid of the semicolons on their own lines...
                                $multiline_value = str_replace(";\r\n", '', $multiline_value);
                                // ...then get rid of any semicolons that weren't on their own lines
                                $multiline_value = str_replace(';', '', $multiline_value);

                                if ( !$apply_blacklist || !self::filterCIFEntity($key) ) {
                                    $structure[] = array(
                                        'key' => $key,
                                        'value' => trim($multiline_value),
                                        'text' => $multiline_text,
                                    );
                                }
                                break;
                            }
                        }
                        else if ( !$inside_semicolon ) {
                            // ...while semicolons are the legitimate method, apparently just allowing
                            //  another line is also "legal"
                            if ( !$apply_blacklist || !self::filterCIFEntity($key) ) {
                                $structure[] = array(
                                    'key' => $key,
                                    'value' => self::stripQuotes($loop_line),
                                    'text' => $multiline_text,
                                );
                            }
                            break;
                        }
                    }

                    // Set the outer loop to read the correct line
                    $i = $j;
                }
            }
            else if ( strpos($line, 'loop_') === 0 ) {
                // loop construct, needs extra work
                $current_node = array('keys' => array(), 'values' => array(), 'text' => $line."\r\n");
                $key_count = 0;
                $loop_values = array();
                $in_data = false;
                $loop_is_blacklisted = false;

                // Need to read lines independently of the outer loop...
                for ($j = $i+1; $j < count($lines); $j++) {
                    $loop_line = trim($lines[$j]);

                    if ( !$in_data ) {
                        if ( strpos($loop_line, '_') === 0 ) {
                            // Still reading keys for the loop...
                            $current_node['keys'][] = $loop_line;
                            // Ensure the key is stored with the node text
                            $current_node['text'] .= $lines[$j]."\r\n";
                        }
                        else {
                            // No longer reading keys
                            $in_data = true;

                            $key_count = count($current_node['keys']);
                            for ($k = 0; $k < $key_count; $k++)
                                $current_node['values'][$k] = array();

                            // If the blacklist needs to be applied...
                            if ( $apply_blacklist ) {
                                // ...then each of the keys needs to be checked
                                foreach ($current_node['keys'] as $num => $key) {
                                    if ( self::filterCIFEntity($key) ) {
                                        // If any of the keys triggers the blacklist, then the entire
                                        //  loop should be skipped
                                        $loop_is_blacklisted = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    if ( $in_data ) {
                        // CIFs don't have something like 'endloop_'...
                        if ( $loop_line === '' || strpos($loop_line, '_') === 0 || strpos($loop_line, 'loop_') === 0 || strpos($loop_line, '#') === 0 ) {
                            // ...if it's a blank line, another line of cif data, or a comment...then end the loop
                            if ( !$loop_is_blacklisted ) {
                                // ...but only store the data if the loop wasn't entirely blacklisted
                                $structure[] = $current_node;
                            }
                            $current_node = null;

                            // Set the outer loop variable so it reads this line next
                            $i = $j-1;
                            break;
                        }
                        else if ( $loop_is_blacklisted ) {
                            // If the loop is blacklisted, then don't attempt to actually process
                            //  any of the lines of data inside the loop...just keep reading until
                            //  the loop ends
                        }
                        else if ( $skip_loop_processing ) {
                            // If not attempting to parse the loop's content for the actual data,
                            //  then just store the text value so it can be retrieved if needed
                            $current_node['text'] .= $lines[$j]."\r\n";
                        }
                        else {
                            // Read in a line of data
                            $multiline_abort = false;
                            $tmp = self::splitLoopValueLine($loop_line, $multiline_abort);

                            if ( !$multiline_abort) {
                                // It's apparently legal to softwrap lines of data inside a "loop_"
                                //  construct, presumably for compatibility with absolutely ancient
                                //  programs and/or terminals that have hard limits.
                                // This is an absolute pain to deal with.
                                for ($k = 0; $k < count($tmp); $k++)
                                    $loop_values[] = $tmp[$k];
                            }
                            else {
                                // ...even worse, it's also seemingly "legal" to splice the
                                //  semicolon-delimited multiline construct into here
                                $semicolon_multiline = $lines[$j]."\r\n";
                                $current_node['text'] .= $lines[$j]."\r\n";
                                do {
                                    // Keep advancing lines until a closing semicolon is hit
                                    $j++;
                                    $semicolon_multiline .= $lines[$j]."\r\n";

                                    // Also ensure the text is stored
                                    $current_node['text'] .= $lines[$j]."\r\n";
                                } while ( trim($lines[$j]) !== ';' );

                                // Store the entire construct, minus the semicolons, by first
                                //  getting rid of the semicolons on their own lines...
                                $semicolon_multiline = str_replace(";\r\n", '', $semicolon_multiline);
                                // ...then by getting rid of any remaining semicolons
                                $semicolon_multiline = str_replace(';', '', $semicolon_multiline);

                                // Store the resulting value
                                $loop_values[] = trim($semicolon_multiline);
                            }

                            if ( count($loop_values) > $key_count ) {
                                // ...naturally, allowing softwraps in loops means somebody can screw
                                //  it up
                                throw new ODRException('Invalid CIF file: broken softwrap in loop_ around line '.$j);
                                // ...or I could screw up the processing too, either/or
                            }
                            else if ( count($loop_values) === $key_count ) {
                                // If the counts match, then transfer this row's data into the node
                                for ($k = 0; $k < count($loop_values); $k++)
                                    $current_node['values'][$k][] = $loop_values[$k];

                                // Ensure the line is stored with the node text
                                if ( !$multiline_abort )
                                    $current_node['text'] .= $lines[$j]."\r\n";
                                // Reset the storage for loop values
                                $loop_values = array();
                            }
                            else {
                                // ...this line didn't have enough pieces of data, so need to read
                                //  another line and hope it has the rest...
                                $current_node['text'] .= $lines[$j]."\r\n";
                            }
                        }
                    }
                }
            }
            else if ( strpos($line, '#') === 0 ) {
                // Comment...want to compress consecutive comments together into a single node
                $current_node = array('key' => 'comment', 'value' => '', 'text' => '');

                while (true) {
                    // Get the next line...
                    $line = trim($lines[$i]);
                    if ( strpos($line, '#') === 0 ) {
                        // Still in comment block...continue to store the text
                        $current_node['text'] .= $line."\r\n";
                        $i++;
                    }
                    else {
                        // No longer in comment block...decrement number so next outer loop iteration
                        //  works without additional effort
                        $i--;
                        // Exit the loop
                        break;
                    }
                }

                // Reset for actual data
                $structure[] = $current_node;
                $current_node = null;
            }
            else if ( strpos($line, 'data_') === 0 ) {
                // Don't particularly want to store this, but it might be needed for a mineral name
                $structure[] = array(
                    'key' => 'data',
                    'value' => substr($line, 5),
                    'text' => $line."\r\n"
                );
            }
            else {
                // empty line
                $structure[] = array(
                    'key' => '',
                    'value' => $line,
                    'text' => $line."\r\n"
                );
            }
        }

        return $structure;
    }


    /**
     * The values in the CIF file tend to be quoted, but it's better for ODR if they're not...
     *
     * @param string $value
     * @return string
     */
    private static function stripQuotes($value)
    {
        $first = substr($value, 0, 1);
        $last = substr($value, -1);

        if ( ($first === "'" && $last === "'" )
            || ($first === '"' && $last === '"')
        ) {
            return substr($value, 1, -1);
        }
        else {
            return $value;
        }
    }


    /**
     * Since CIF files permit tables of arbitrary number of columns, it's easier to split them apart
     * into an array of values in its own function.
     *
     * The caveat is that sometimes the value is semicolon delimited and spread across multiple lines,
     * so the function uses an extra parameter to return that case...
     *
     * @param string $line should be trimmed already
     * @param bool $multiline_abort
     * @return array
     */
    private static function splitLoopValueLine($line, &$multiline_abort)
    {
        $data = array();

        $tmp = '';
        $in_quotes = false;
        for ($i = 0; $i < strlen($line); $i++) {
            if ( $line[$i] === "'" ) {
                if ( !$in_quotes ) {
                    // Start of new quote
                    $in_quotes = true;
                }
                else {
                    // End of the quote, store the data and reset
                    $in_quotes = false;
                    $data[] = $tmp;
                    $tmp = '';
                    $i += 1;
                }

                // Don't want to store the quotation mark
            }
            else if ( $line[$i] === ' ' ) {
                // Hit a space...
                if ( $in_quotes ) {
                    // ...if in quotes, save it
                    $tmp .= $line[$i];
                }
                else if ( $tmp !== '' ) {
                    // ...if not in quotes, then store the previous piece when there was something
                    //  in it and not just space justification
                    $data[] = $tmp;
                    $tmp = '';
                }
            }
            else if ( $line[$i] === ';' && !$in_quotes ) {
                // It's apparently "legal" to softwrap lines of data inside a "loop_" construct,
                //  presumably for compatibility with absolutely ancient programs and/or terminals
                //   that have hard limits.

                // This is incredibly painful, because operating on a single line of data is easier...
                $multiline_abort = true;
                // There shouldn't be any lingering piece to store here
//                $data[] = $tmp;
                return $data;
            }
            else {
                // Store any other character
                $tmp .= $line[$i];
            }
        }

        // Store any lingering piece and return
        if ( $tmp !== '' )
            $data[] = $tmp;

        // TODO - apparently splitting a value of "-0.8" into "-" on one line and "0.8" on the next is legal.  WTF.

        return $data;
    }


    /**
     * Returns whether the given key is on {@link self::$cif_key_blacklist} and simultaneously not
     * on {@link self::$cif_key_whitelist}.
     *
     * @param string $key
     * @return bool true if the key is blacklisted, false if it is whitelisted
     */
    private static function filterCIFEntity($key) {
        // Most of the blacklist only has a single string inbetween underscores...e.g. "_shelx_"...
        //  but there are rarer situation where we want to be more specific...e.g.  "_pd_calc_"
        $fragment_1 = $fragment_2 = null;
        $num_underscores = 0;
        for ($i = 0; $i < strlen($key); $i++) {
            if ( $key[$i] === '_' )
                $num_underscores++;
            if ( is_null($fragment_1) && $num_underscores === 2 )
                $fragment_1 = substr($key, 0, $i+1);
            else if ( is_null($fragment_2) && $num_underscores === 3 )
                $fragment_2 = substr($key, 0, $i+1);
        }

        // If the key is on the blacklist and not on the whitelist, then return true to filter it out
        if ( !is_null($fragment_1) && isset(self::$cif_key_blacklist[$fragment_1]) && !isset(self::$cif_key_whitelist[$key]) )
            return true;
        if ( !is_null($fragment_2) && isset(self::$cif_key_blacklist[$fragment_2]) && !isset(self::$cif_key_whitelist[$key]) )
            return true;

        // Otherwise, AMCSD doesn't want the key filtered out
        return false;
    }
}
