Clazz.declarePackage("J.shapebio");
Clazz.load(["J.shapebio.Rockets"], "J.shapebio.Cartoon", null, function(){
var c$ = Clazz.declareType(J.shapebio, "Cartoon", J.shapebio.Rockets);
Clazz.overrideMethod(c$, "initShape", 
function(){
this.setTurn();
this.madDnaRna = 1000;
});
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
