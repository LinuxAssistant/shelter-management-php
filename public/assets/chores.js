const choreFacility=document.querySelector("form select[name=facility_id]");
    const choreResident=document.getElementById("chore-resident");
    const choreAssigneeType=document.querySelector("form select[name=assignee_type]");
    function filterChoreResidents() {
        for (const option of choreResident.options) {
            if (!option.value) continue;
            option.hidden=option.dataset.facility!==choreFacility.value;
            option.disabled=option.hidden;
        }
        if (choreResident.selectedOptions[0]?.disabled) choreResident.value="";
    }
    choreFacility.addEventListener("change",filterChoreResidents);
    choreAssigneeType.addEventListener("change",()=>{choreResident.disabled=choreAssigneeType.value!=="resident";if(choreResident.disabled)choreResident.value="";});
    filterChoreResidents();choreResident.disabled=true;
