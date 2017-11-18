Feature: ODR Screenshots and Browser Tests

  Scenario: Test of Epsilon ODR
    When We open page
    Then We should store the BrowserStack session id
    Then We should find "Login"
    Then We should log in
    Then We should see "Change in number"
    Then We should also see "Search"

