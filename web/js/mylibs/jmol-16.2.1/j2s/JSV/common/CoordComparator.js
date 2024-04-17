Clazz.declarePackage("JSV.common");
(function(){
var c$ = Clazz.declareType(JSV.common, "CoordComparator", null, java.util.Comparator);
Clazz.overrideMethod(c$, "compare", 
function(c1, c2){
return (c1.getXVal() > c2.getXVal() ? 1 : c1.getXVal() < c2.getXVal() ? -1 : 0);
}, "JSV.common.Coordinate,JSV.common.Coordinate");
})();
;//5.0.1-v2 Tue Mar 12 13:10:23 CDT 2024
