# Solar self-consumption model

- using high resolution 10s solar generation data from an emoncms feed
- creates household consumption model to experiment with load timing and types
- simulates PV Diversion with hot water cylinder
- simple EV charging profile option
- saves modelled consumption feed and cylinder temperature to output emoncms feeds

![model.png](model.png)

![solarmodeloutput.png](solarmodeloutput.png)

The degree of self consumption makes all the difference to the economics of domestic solar PV. Especially with recent reductions in feed in tariff rates in the UK and a view looking forward to unsubsidised solar.

Traditional household demand peaks in the morning and evenings on weekdays with a slightly more even profile on weekends. Solar generates most in the middle of the day resulting in a mismatch between supply and demand.

Traditional demand without using excess solar for hot water, smart charging of EV’s or battery stores results in a self consumption of around 15-25%.

The following table shows outline 20 year savings for different self consumption levels and income from FIT + export tariffs for a 4kW grid-tie solar pv system costing £6000 (installed 2017). Self consumption savings are based on a standard electricity price of 15 p/kWh. Figures have been rounded to the nearest £100. Calculation assumes no energy cost inflation above retail-price-index to keep things simple:

TABLE

For a view towards unsubsidised solar, this time over a potential 35 year system lifespan including an inverter replacement:

TABEL

### Increasing self-consumption 

**1. Diversion of excess solar to hot water**

For households with immersion or instantaneous domestic hot water heating provided for on a standard electricity tariff, using solar to heat water at times of excess supply can make a lot of sense. The simplest way of doing this is with a PV Diverter – a box of power electronics that measures the amount of excess solar available and ‘diverts’ this excess electricity to an immersion heater. A more efficient but more complex way of doing this and more limited in terms of responsiveness would be to heat hot water via a heat pump.

**2. Day time and smart charging of an electric vehicle.**

Smart charging an EV when the sun is shining could also increase self consumption, particularly useful to anyone that works partly from home, or where the car is left at home perhaps on sunny days and its possible to cycle to work? Alternatively smart charging of EV’s at work would also make a lot of sense.

**3. Battery storage**

Another option much talked about at the moment due to the dropping price, a battery store can soak up solar during the day and make it available later in the evening when demand is usually highest.

### Assessing the impact of different solutions on self consumption

In order to quickly get an idea for the potential levels of self consumption that could be reached with different demand patterns, PV Diversion, EV charging and battery storage we have constructed a detailed energy model. The model uses high resolution 10s solar pv data collected by monitoring, constructs a detailed model of household demand and from this calculates the degree of self consumption that would result from different household demand profiles.

The household model covers the traditional demands such as: lighting, computers, internet router, central heating standby, kettle, electric shower, electric cooking, fridge/freezer, washing machine. Each with start and end times, power levels and in the case of fridge/freezer cycle repeat times. Weekday and weekend schedules are also taken into account.

‘Traditional’ electricity demand can then be added to with PV Diversion, smart EV charging and battery storage to explore how self consumption increases.

The model includes basic cost data in order to calculate payback times and the resulting unit cost of the delivered useful electricity. The cost model takes into account inverter and if applicable battery replacement cost and assumes price reductions in these components over time.

### Example results

The following example results are for a household with low traditional electricity consumption due to use of LED lighting and efficient appliances. Electricity consumption for this household is currently 4.7 kWh/d of which standby is 0.7 kWh/d, electric shower is 1.5 kWh/d, fridge is 0.4 kWh/d, lighting and computers ~ 1.0 kWh/d and electric cooking 1.1 kWh/d.

For this baseline household a number of scenarios are modelled from adding solar by itself, to combination with PV diversion, EV demand and battery storage.

