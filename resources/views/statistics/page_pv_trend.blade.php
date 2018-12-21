<div id="page_pv_trend">
    <form id="page_pv_trend_form" class="form-inline">
        <div class="form-group margin-r-5">
            <label>维度:</label>
            <select id="page_pv_trend_date_type" name="date_type" class="form-control">
                <option value="day">天</option>
                <option value="month">月</option>
                <option value="year">年</option>
            </select>
        </div>

        <div class="form-group margin-r-5">
            <label>页面:</label>
            <select id="page_pv_trend_path_select" name="path" class="form-control">
            </select>
        </div>

        <div class="form-group margin-r-5">
            <label>区间:</label>
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                <input id="page_pv_trend_started_at" type="text" name="started_at" class="form-control started_at">
            </div>
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                <input id="page_pv_trend_ended_at" type="text" name="ended_at" class="form-control ended_at">
            </div>
        </div>
        <button id="page_pv_trend_submit" type="submit" class="btn btn-primary">提交</button>
    </form>
    <hr>

    <div id="chart" style="height:400px;"></div>
</div>


<script>

    $(document).ready(function () {

        //Chart 对象
        let chart = null;
        // 指定图表的配置项和数据
        let chartOption = null;


        dateChoiceInit('day');
        chartInit();

        $.when(pagePathSelect())
            .done(function () {
                const newForm = new FormData($("#page_pv_trend #page_pv_trend_form")[0]);
                requestNewData(newForm);
            });


        //维度选择事件
        $("#page_pv_trend #page_pv_trend_date_type").on("change", function () {
            // console.log($(this).val());

            updateDateChoice($(this).val());

            //重新请求数据
            const newForm = new FormData($("#page_pv_trend #page_pv_trend_form")[0]);
            requestNewData(newForm);
        });


        //提交按钮点击事件
        $("#page_pv_trend #page_pv_trend_submit").on('click', function () {
            const newForm = new FormData($("#page_pv_trend #page_pv_trend_form")[0]);
            requestNewData(newForm);

            return false;
        });


        /**
         * 请求数据
         * @param form
         */
        function requestNewData(form) {
            let requestData = null;

            if ($('#subject_uuid').val() !== undefined) {
                form.append("subject_uuid", $('#subject_uuid').val());
            }

            let dataRequest = doAjaxForForm("/admin/statistics/page/pv/trend", "POST",
                form, function (data) {
                    requestData = data;
                });


            $.when(dataRequest)
                .done(function () {
                    let renderData = Array.from(requestData
                        .values());

                    // console.log(renderData);

                    if (renderData && renderData.length > 0) {
                        chartOption.dataset = {
                            source: renderData
                        };
                        chart.setOption(chartOption);
                    } else {
                        chart.clear();
                    }
                })
                .fail(function () {
                    // alert("加载数据失败,请刷新重试");
                });
        }


        //根据纬度的变化,更新日期控件
        function updateDateChoice(type) {
            let startDate;
            let endDate;
            //日历控件默认配置
            let dateOptions = {
                // format: 'YYYY-MM-DD',
                locale: '{{config("app.locale")}}',
                // minDate: '2015-12-01',
                maxDate: moment().format('YYYY-MM-DD')
            };

            const defaultStarted = moment().add(-31, 'days').format('YYYY-MM-DD');
            const defaultEnded = moment().format('YYYY-MM-DD');

            switch (type) {
                case 'day':
                    startDate = moment().add(-31, 'days').format('YYYY-MM-DD');
                    endDate = moment().format('YYYY-MM-DD');
                    dateOptions.format = 'YYYY-MM-DD';
                    dateOptions.minDate = '2018-10-01';
                    break;
                case 'month':
                    startDate = moment().add(-12, 'months').format('YYYY-MM');
                    endDate = moment().format('YYYY-MM');
                    dateOptions.format = 'YYYY-MM';
                    dateOptions.minDate = '2018-10';
                    break;
                case 'year':
                    // startDate = moment().add(-10, 'years').format('YYYY');
                    startDate = '2015';

                    endDate = moment().format('YYYY');
                    dateOptions.format = 'YYYY';
                    dateOptions.minDate = '2018';

                    break;
            }

            if ($('#page_pv_trend #page_pv_trend_started_at').data("DateTimePicker")) {
                $('#page_pv_trend #page_pv_trend_started_at').data("DateTimePicker").destroy();
            }
            if ($('#page_pv_trend #page_pv_trend_ended_at').data("DateTimePicker")) {
                $('#page_pv_trend #page_pv_trend_ended_at').data("DateTimePicker").destroy();
            }


            $('#page_pv_trend #page_pv_trend_started_at').datetimepicker(Object.assign(dateOptions, {
                defaultDate: defaultStarted,
                date: startDate
            }));


            $('#page_pv_trend #page_pv_trend_ended_at').datetimepicker(Object.assign(dateOptions, {
                defaultDate: defaultEnded,
                date: endDate
            }));
        }


        //页面选择控件初始化,包括请求数据填充
        function pagePathSelect() {
            //1. 请求page path数据
            let subjectId;

            if ($('#subject_uuid').val() !== undefined) {
                subjectId = $('#subject_uuid').val();
            }

            return doAjax("/admin/statistics/page/pv/page_paths", "POST",
                {
                    "subject_uuid": subjectId
                }, function (datas) {
                    let selectOption = "";
                    let defaultPath = "";
                    let i = 0;
                    let hasOwn = Object.prototype.hasOwnProperty;
                    for (let key in datas) {
                        if (hasOwn.call(datas, key)) {
                            selectOption += '<option value="' + key + '">' + datas[key] + '</option>';
                            if (i === 0) {
                                defaultPath = key;
                            }
                            i++;
                        }
                    }

                    $('#page_pv_trend #page_pv_trend_path_select').html(selectOption);
                    $("#page_pv_trend_path_select option[value='" + defaultPath + "']").prop("selected", "selected");

                });
        }


        //时间选择控件初始化
        function dateChoiceInit(type) {
            //设置默认的时间维度选择
            //month
            $("#page_pv_trend #page_pv_trend_date_type option[value='" + type + "']").prop("selected", 'selected');
            updateDateChoice(type);
        }


        /**
         * 表格初始化
         */
        function chartInit() {
            chartOption = {
                title: {},
                toolbox: {
                    show: true,
                    feature: {
                        magicType: {type: ['line', 'bar']},
                        restore: {},
                        saveAsImage: {}
                    }
                },
                tooltip: {
                    trigger: 'axis',
                    axisPointer: {
                        type: 'cross'
                    },
                },
                legend: {},
                xAxis: {
                    type: 'category',
                },
                yAxis: {
                    name: '页面访问量',
                    min: "dataMin"
                },
                series: [{
                    name: '页面访问量',
                    type: 'line',
                    markPoint: {
                        data: [
                            {type: 'max', name: '最大值'},
                            {type: 'min', name: '最小值'}
                        ]
                    },
                }]
            };

            chart = echarts.init($("#page_pv_trend #chart")[0], 'walden');
            window.addEventListener('resize', () => chart.resize(), false);
        }
    });


</script>
