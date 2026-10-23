'use strict';
const facilityData=JSON.parse(document.getElementById('facility-data').textContent);
const facilitySelect=document.getElementById('edit-facility');
function loadFacility(){
 const facility=facilityData.find(item=>String(item.id)===facilitySelect.value);
 if(!facility)return;
 document.getElementById('facility-name').value=facility.name;
 document.getElementById('facility-timezone').value=facility.timezone;
 document.getElementById('facility-address').value=facility.address;
}
facilitySelect.addEventListener('change',loadFacility);
loadFacility();
