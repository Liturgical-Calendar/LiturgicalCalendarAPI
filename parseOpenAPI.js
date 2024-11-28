/**
 * parseOpenAPI
 * Will substitute all external references with the referenced values
 *   in order to make the `rdme` script happy
 */

var fs = require('fs');
var openAPI = fs.readFileSync('jsondata/schemas/openapi.json', 'utf8');

var obj = JSON.parse(openAPI);

function flattenObject(ob) {
    var toReturn = {};

    for (var i in ob) {
        if (!ob.hasOwnProperty(i)) continue;

        if ((typeof ob[i]) == 'object' && ob[i] !== null) {
            var flatObject = flattenObject(ob[i]);
            for (var x in flatObject) {
                if (!flatObject.hasOwnProperty(x)) continue;

                toReturn[i + '.' + x] = flatObject[x];
            }
        } else {
            toReturn[i] = ob[i];
        }
    }
    return toReturn;
}

const unFlatten = (obj,keys) => {
    keys = keys.map(key => isNaN(Number(key)) ? key : Number(key) );
    return keys.reduce((prevVal,curVal) => prevVal[curVal], obj);
}

/**
 * setNewValForFlattenedPath
 *  there must be a better way of doing this? what if an object is nested more than 15 levels?
 * @param {*} obj
 * @param {*} keys
 * @param {*} newVal
 * @returns
 */
const setNewValForFlattenedPath = (obj,keys,newVal) => {
    keys = keys.map(key => isNaN(Number(key)) ? key : Number(key) );
    const keysLen = keys.length;
    switch(keysLen) {
        case 1: obj[keys[0]] = newVal; break;
        case 2: obj[keys[0]][keys[1]] = newVal; break;
        case 3: obj[keys[0]][keys[1]][keys[2]] = newVal; break;
        case 4: obj[keys[0]][keys[1]][keys[2]][keys[3]] = newVal; break;
        case 5: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]] = newVal; break;
        case 6: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]] = newVal; break;
        case 7: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]] = newVal; break;
        case 8: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]] = newVal; break;
        case 9: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]][keys[8]] = newVal; break;
        case 10: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]][keys[8]][keys[9]] = newVal; break;
        case 11: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]][keys[8]][keys[9]][keys[10]] = newVal; break;
        case 12: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]][keys[8]][keys[9]][keys[10]][keys[11]] = newVal; break;
        case 13: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]][keys[8]][keys[9]][keys[10]][keys[11]][keys[12]] = newVal; break;
        case 14: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]][keys[8]][keys[9]][keys[10]][keys[11]][keys[12]][keys[13]] = newVal; break;
        case 15: obj[keys[0]][keys[1]][keys[2]][keys[3]][keys[4]][keys[5]][keys[6]][keys[7]][keys[8]][keys[9]][keys[10]][keys[11]][keys[12]][keys[13]][keys[14]] = newVal; break;
    }
    return obj;
}


const substituteRefs = (objWithRefs,flatObject) => {
    for(const [key,value] of Object.entries(flatObject)) {
      let myKeys = key.split('.');
      if(myKeys.includes('$ref') && value.includes('https://litcal.johnromanodorazio.com/api/dev/jsondata/schemas/') ) {
        let schemaFileAndHashValue = value.replace('https://litcal.johnromanodorazio.com/api/dev/','').split('#/');
        let hashKeys = schemaFileAndHashValue[1].split('/');
        let schemaFile = fs.readFileSync(schemaFileAndHashValue[0], 'utf8');
        let schemaObj = JSON.parse(schemaFile);
        let schemaHashObj = unFlatten(schemaObj,hashKeys);
        const schemaHashFlatObject = flattenObject(schemaHashObj);
        for(const [flatKey,flatValue] of Object.entries(schemaHashFlatObject)) {
            const flatKeys = flatKey.split('.');
            if(flatKeys.includes('$ref') && flatValue.startsWith('#/definitions/') ) {
                let flatHashKeys = flatValue.replace('#/', '').split('/');
                //console.log('will now unflatten path ' + flatValue);
                let flatSchemaHashObj = unFlatten(schemaObj,flatHashKeys);
                flatKeys.pop();
                //console.log('\t>> setting new value for path ' + flatKey);
                schemaHashObj = setNewValForFlattenedPath(schemaHashObj,flatKeys,flatSchemaHashObj);
            }
        }
        const newSchemaHashFlatObject = flattenObject(schemaHashObj);
        for(const [newFlatKey,newFlatValue] of Object.entries(newSchemaHashFlatObject)) {
            const newFlatKeys = newFlatKey.split('.');
            if(newFlatKeys.includes('$ref') && newFlatValue.startsWith('#/definitions/') ) {
                let newFlatHashKeys = newFlatValue.replace('#/', '').split('/');
                //console.log('will now unflatten path ' + newFlatValue);
                let newFlatSchemaHashObj = unFlatten(schemaObj,newFlatHashKeys);
                newFlatKeys.pop();
                //console.log('\t>>\t>> setting new value for path ' + newFlatKey);
                schemaHashObj = setNewValForFlattenedPath(schemaHashObj,newFlatKeys,newFlatSchemaHashObj);
            }
        }
        myKeys.pop();
        //console.log('setting new value for path ' + key);
        objWithRefs = setNewValForFlattenedPath(obj,myKeys,schemaHashObj);
      }
    }
    const newFlatObject = flattenObject(objWithRefs);
    for(const [keyX,valueX] of Object.entries(newFlatObject)) {
        let myKeysX = keyX.split('.');
        if(myKeysX.includes('$ref') && valueX.includes('https://litcal.johnromanodorazio.com/api/dev/jsondata/schemas/') ) {
            objWithRefs = substituteRefs(objWithRefs,newFlatObject);
        }
    }
    return objWithRefs;
}

const flatObject = flattenObject(obj);
obj = substituteRefs(obj,flatObject);

//console.log(obj);
fs.writeFileSync('parsedOpenAPI.json', JSON.stringify(obj, null, 2));
