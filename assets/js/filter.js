$(document).ready(function () {
  let filter_make  =$('#filter_make');
  let filter_model =$('#filter_model');
  let first_model_options = "<option value=''>-Model-</option>";
  let filter_year  =$('#filter_year');
  let first_year_options = "<option value=''>-Year-</option>";
  let make = $('#filter_make').val();
  filter_make.on('change',function(){
    if(this.value==""){
      filter_model.attr('disabled',true);
      filter_year.attr('disabled',true);
      filter_model.html(first_model_options);
    }else{
      let models = filter_features[this.value];
      let options = [];
      for(key in models){
        let option = [sub_features[key],key];
        options.push(option)
      }
      options = options.sort()
      let options_html = "";
      options.forEach(
        function(value){
          options_html += "<option value="+value[1]+">"+value[0]+"</option>";
        }
      )
      filter_model.html(first_model_options+options_html);
      filter_model.attr('disabled',false);
      filter_year.html(first_year_options);
    }
    make = this.value;
  });
  filter_model.on('change', function(){
    if(this.value==""){
      filter_year.attr('disabled',true);
      filter_year.html(first_year_options);      
    }else{
      filter_year.attr('disabled',false);
      let years = filter_features[make][this.value];
      let options = [];
      years.forEach(
        function(key){
          let option = [sub_features[key],key];
          options.push(option)
        }
      )
      options = options.sort().reverse()
      let options_html = "";
      options.forEach(
        function(value){
          options_html += "<option value="+value[1]+">"+value[0]+"</option>";
        }
      )
      filter_year.html(first_year_options+options_html);      
    }
  });
});