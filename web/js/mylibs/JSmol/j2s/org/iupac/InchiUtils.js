Clazz.declarePackage("org.iupac");
(function(){
var c$ = Clazz.declareType(org.iupac, "InchiUtils", null);
c$.getAtomicNumber = Clazz.defineMethod(c$, "getAtomicNumber", 
function(sym){
if (sym.length == 1) {
switch ((sym.charAt(0)).charCodeAt(0)) {
case 72:
return 1;
case 66:
return 5;
case 67:
return 6;
case 79:
return 7;
case 70:
return 8;
case 80:
return 15;
case 83:
return 16;
}
}return Clazz.doubleToInt("xxH HeLiBeB C N O F NeNaMgAlSiP S ClArK CaScTiV CrMnFeCoNiCuZnGaGeAsSeBrKrRbSrY ZrNbMoTcRuRhPdAgCdInSnSbTeI XeCsBaLaCePrNdPmSmEuGdTbDyHoErTmYbLuHfTaW ReOsIrPtAuHgTlPbBiPoAtRnFrRaAcThPaU NpPuAmCmBkCfEsFmMdNoLrRfDbSgBhHsMtDsRgCnNhFlMcLvTsOg".indexOf(sym) / 2);
}, "~S");
c$.getActualMass = Clazz.defineMethod(c$, "getActualMass", 
function(sym, mass){
if (mass < 900) {
return mass;
}var atno = org.iupac.InchiUtils.getAtomicNumber(sym);
return (mass - 10000) + org.iupac.InchiUtils.inchiAveAtomicMass[atno - 1];
}, "~S,~N");
c$.inchiAveAtomicMass =  Clazz.newIntArray(-1, [1, 4, 7, 9, 11, 12, 14, 16, 19, 20, 23, 24, 27, 28, 31, 32, 35, 40, 39, 40, 45, 48, 51, 52, 55, 56, 59, 59, 64, 65, 70, 73, 75, 79, 80, 84, 85, 88, 89, 91, 93, 96, 98, 101, 103, 106, 108, 112, 115, 119, 122, 128, 127, 131, 133, 137, 139, 140, 141, 144, 145, 150, 152, 157, 159, 163, 165, 167, 169, 173, 175, 178, 181, 184, 186, 190, 192, 195, 197, 201, 204, 207, 209, 209, 210, 222, 223, 226, 227, 232, 231, 238, 237, 244, 243, 247, 247, 251, 252, 257, 258, 259, 260, 261, 270, 269, 270, 270, 278, 281, 281, 285, 278, 289, 289, 293, 297, 294]);
})();
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
